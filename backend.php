<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

$host = '127.0.0.1';
$db   = 'nhas_assurecare_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'ERROR', 'message' => 'MySQL Error: ' . $e->getMessage()]);
    exit;
}

function appendImmutableLedger(
    PDO $pdo,
    string $cardNo,
    ?int $providerId,
    ?int $parentHospId,
    ?int $rxId,
    string $sectionType,
    string $direction,
    string $creditSource,
    float $amount,
    float $hospShare,
    float $provShare,
    string $description,
    int $txYear
): string {
    $stmt = $pdo->query("SELECT curr_hash FROM transactions_ledger ORDER BY tx_id DESC LIMIT 1");
    $prevHash = $stmt->fetchColumn() ?: hash('sha256', 'NHAS_GENESIS_2026');

    $payload = implode('|', [
        $prevHash, $cardNo, (string)$providerId, (string)$parentHospId, (string)$rxId,
        $sectionType, $direction, $creditSource, number_format($amount, 2, '.', ''),
        $description, $txYear, microtime(true)
    ]);
    $currHash = hash('sha256', $payload);

    $ins = $pdo->prepare(
        "INSERT INTO transactions_ledger
        (assurecare_card_no, provider_id, parent_hospital_id, rx_id, section_type, entry_direction, credit_source, amount, hospital_share_amount, provider_share_amount, description, prev_hash, curr_hash, tx_year)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        $cardNo, $providerId, $parentHospId, $rxId, $sectionType, $direction,
        $creditSource, $amount, $hospShare, $provShare, $description, $prevHash, $currHash, $txYear
    ]);
    return $currHash;
}

function computePayoutSplit(PDO $pdo, array $provider, float $amount): array {
    $parentHospId = null;
    $hospShare = 0.00;
    $provShare = $amount;

    if (!empty($provider['parent_hospital_card'])) {
        $hStmt = $pdo->prepare("SELECT provider_id FROM providers_registry WHERE provider_card_no = ? AND role_type = 'HOSPITAL'");
        $hStmt->execute([$provider['parent_hospital_card']]);
        $hosp = $hStmt->fetch();
        if ($hosp) {
            $parentHospId = (int)$hosp['provider_id'];
            $pct = max(0.0, min(100.0, (float)$provider['hospital_share_pct']));
            $hospShare = round($amount * ($pct / 100.0), 2);
            $provShare = round($amount - $hospShare, 2);
        }
    }
    return [$parentHospId, $hospShare, $provShare];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'get_dummy_kyc_list':
            $rows = $pdo->query("SELECT * FROM mock_govt_kyc_registry ORDER BY kyc_id ASC")->fetchAll();
            echo json_encode(['status' => 'OK', 'kyc_records' => $rows]);
            break;

        case 'fetch_kyc_by_id':
            $idNum = strtoupper(trim($_GET['id_number'] ?? ''));
            $stmt = $pdo->prepare("SELECT * FROM mock_govt_kyc_registry WHERE id_number = ? OR secondary_id = ?");
            $stmt->execute([$idNum, $idNum]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException("ID not found in Govt KYC Registry.");
            echo json_encode(['status' => 'OK', 'record' => $row]);
            break;

        case 'register_beneficiary':
            $aadhaar = preg_replace('/\D/', '', $_POST['aadhaar_no'] ?? '');
            if (strlen($aadhaar) !== 12) throw new RuntimeException("Valid 12-digit Citizen ID Number is required.");

            $kStmt = $pdo->prepare("SELECT * FROM mock_govt_kyc_registry WHERE entity_type='CITIZEN' AND id_number = ?");
            $kStmt->execute([$aadhaar]);
            $kyc = $kStmt->fetch();
            if (!$kyc) throw new RuntimeException("Citizen ID not verified in Govt KYC Registry. Click Fetch first.");

            $year = (int)date('Y');
            $rand4 = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $cardNo = 'AC-' . $year . '-' . random_int(1000, 9999) . '-' . $rand4;
            $aadhaarLast4 = substr($aadhaar, -4);

            $pdo->beginTransaction();
            $ins = $pdo->prepare(
                "INSERT INTO users_beneficiaries (aadhaar_no, aadhaar_last4, assurecare_card_no, card_last4, full_name, dob, gender, mobile_no, district)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$aadhaar, $aadhaarLast4, $cardNo, $rand4, $kyc['full_name'], $kyc['dob_or_incorp'], $kyc['gender_or_cat'], $kyc['mobile_no'], $kyc['district']]);

            $pdo->prepare("INSERT INTO credit_wallets (assurecare_card_no, calendar_year) VALUES (?, ?)")
                ->execute([$cardNo, $year]);

            appendImmutableLedger(
                $pdo, $cardNo, null, null, null, 'SYSTEM_CREDIT', 'CREDIT', 'BASE_5L_ZERO_INT',
                500000.00, 0.00, 0.00, "Annual NHAS Base Health Limit Credited (01-Jan to 31-Dec-$year)", $year
            );
            $pdo->commit();

            echo json_encode([
                'status' => 'OK',
                'card_no' => $cardNo,
                'card_last4' => $rand4,
                'aadhaar_last4' => $aadhaarLast4,
                'full_name' => $kyc['full_name']
            ]);
            break;

        case 'register_provider':
            $role = $_POST['role_type'] ?? 'DOCTOR';
            $orgName = strtoupper(trim($_POST['org_name'] ?? ''));
            $bankAcc = trim($_POST['bank_account_no'] ?? '');
            $bankIfsc = strtoupper(trim($_POST['bank_ifsc'] ?? ''));
            $mobile = trim($_POST['mobile_no'] ?? '');
            $district = trim($_POST['district'] ?? 'Lucknow');
            $parentHospCard = strtoupper(trim($_POST['parent_hospital_card'] ?? ''));
            $hospSharePct = (float)($_POST['hospital_share_pct'] ?? 0);

            if (empty($orgName) || empty($bankAcc) || empty($bankIfsc)) {
                throw new RuntimeException("Provider Name and Payout Bank Account + IFSC are mandatory.");
            }

            if (!empty($parentHospCard)) {
                if ($role === 'HOSPITAL') throw new RuntimeException("A Hospital cannot be affiliated under another Hospital.");
                $hChk = $pdo->prepare("SELECT provider_id FROM providers_registry WHERE provider_card_no = ? AND role_type = 'HOSPITAL'");
                $hChk->execute([$parentHospCard]);
                if (!$hChk->fetchColumn()) {
                    throw new RuntimeException("Parent Hospital Card ID ($parentHospCard) not found. Register the Hospital first.");
                }
            } else {
                $parentHospCard = null;
                $hospSharePct = 0.00;
            }

            $docDegrees = null; $docAadhaar = null; $docAadhaarLast4 = null;
            $bizPan = strtoupper(trim($_POST['business_pan'] ?? ''));
            $gstNo = strtoupper(trim($_POST['gst_number'] ?? ''));
            $newFee = (float)($_POST['new_visit_fee'] ?? 0);
            $revisitDays = (int)($_POST['revisit_validity_days'] ?? 0);
            $maxPerDay = (int)($_POST['max_patients_per_day'] ?? 30);

            if ($role === 'DOCTOR') {
                $docDegrees = trim($_POST['doctor_degrees'] ?? '');
                $docAadhaar = preg_replace('/\D/', '', $_POST['doctor_aadhaar'] ?? '');
                if (strlen($docAadhaar) !== 12 || empty($docDegrees) || empty($bizPan)) {
                    throw new RuntimeException("Doctor registration requires Medical Degrees, 12-digit ID, PAN Card & Bank Details.");
                }
                $docAadhaarLast4 = substr($docAadhaar, -4);
                $gstNo = null;
            } else {
                if (empty($bizPan) || empty($gstNo)) {
                    throw new RuntimeException("Hospital / Lab / Medical Store requires Business PAN Card, GST Number & Bank Details.");
                }
            }

            $rand4 = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $pCard = 'PR-' . substr($role, 0, 4) . '-' . $rand4;

            $ins = $pdo->prepare(
                "INSERT INTO providers_registry
                (provider_card_no, card_last4, role_type, org_or_doctor_name, parent_hospital_card, hospital_share_pct, doctor_degrees, doctor_aadhaar, doctor_aadhaar_last4, business_pan, gst_number, bank_account_no, bank_ifsc, mobile_no, district, new_visit_fee, revisit_validity_days, max_patients_per_day, is_verified)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            );
            $ins->execute([
                $pCard, $rand4, $role, $orgName, $parentHospCard, $hospSharePct,
                $docDegrees, $docAadhaar, $docAadhaarLast4,
                $bizPan, $gstNo, $bankAcc, $bankIfsc, $mobile, $district,
                $newFee, $revisitDays, $maxPerDay
            ]);

            echo json_encode([
                'status' => 'OK',
                'provider_card_no' => $pCard,
                'card_last4' => $rand4,
                'name' => $orgName,
                'role' => $role
            ]);
            break;

        case 'login_role':
            $loginRole = $_POST['login_role'] ?? 'PATIENT';
            $primaryId = strtoupper(trim($_POST['primary_id'] ?? ''));
            $last4 = trim($_POST['last4_secret'] ?? '');

            if (strlen($last4) !== 4) {
                throw new RuntimeException("Enter exact 4 digits.");
            }

            if ($loginRole === 'PATIENT') {
                $stmt = $pdo->prepare("SELECT * FROM users_beneficiaries WHERE aadhaar_no = ? OR assurecare_card_no = ?");
                $stmt->execute([$primaryId, $primaryId]);
                $ben = $stmt->fetch();
                if (!$ben) throw new RuntimeException("Patient not found. Register first or check ID / Card Number.");

                if ($last4 !== $ben['aadhaar_last4'] && $last4 !== $ben['card_last4']) {
                    throw new RuntimeException("Login Failed! Enter Last 4 digits of your ID Number OR Virtual Card.");
                }
                $_SESSION['user_role'] = 'PATIENT';
                $_SESSION['card_no'] = $ben['assurecare_card_no'];
                echo json_encode(['status' => 'OK', 'redirect' => 'patient.html', 'user' => $ben]);
            } elseif ($loginRole === 'DOCTOR') {
                $stmt = $pdo->prepare("SELECT * FROM providers_registry WHERE role_type='DOCTOR' AND (doctor_aadhaar = ? OR provider_card_no = ?)");
                $stmt->execute([$primaryId, $primaryId]);
                $doc = $stmt->fetch();
                if (!$doc) throw new RuntimeException("Doctor not found. Register first or check ID / Virtual Card Number.");

                if ($last4 !== $doc['doctor_aadhaar_last4'] && $last4 !== $doc['card_last4']) {
                    throw new RuntimeException("Login Failed! Enter Last 4 digits of your ID Number OR Virtual Card.");
                }
                $_SESSION['user_role'] = 'DOCTOR';
                $_SESSION['provider_id'] = $doc['provider_id'];
                echo json_encode(['status' => 'OK', 'redirect' => 'doctor.html', 'provider' => $doc]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM providers_registry WHERE role_type = ? AND (provider_card_no = ? OR business_pan = ?)");
                $stmt->execute([$loginRole, $primaryId, $primaryId]);
                $org = $stmt->fetch();
                if (!$org) throw new RuntimeException("$loginRole not found. Register first or check Virtual Card Number.");

                if ($last4 !== $org['card_last4']) {
                    throw new RuntimeException("Login Failed! Enter the Last 4 digits of your Virtual Card Number ({$org['provider_card_no']}).");
                }
                $_SESSION['user_role'] = $loginRole;
                $_SESSION['provider_id'] = $org['provider_id'];
                $redir = 'doctor.html';
                if ($loginRole === 'DIAGNOSTIC_LAB') $redir = 'lab_login.html';
                elseif ($loginRole === 'PHARMACY') $redir = 'med_login.html';
                echo json_encode(['status' => 'OK', 'redirect' => $redir, 'provider' => $org]);
            }
            break;

        case 'get_session':
            echo json_encode(['status' => 'OK', 'session' => $_SESSION]);
            break;

        case 'logout':
            session_destroy();
            echo json_encode(['status' => 'OK']);
            break;

        case 'lookup_card':
            $cardNo = trim($_GET['card_no'] ?? '');
            $provId = (int)($_GET['provider_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM users_beneficiaries WHERE assurecare_card_no = ?");
            $stmt->execute([$cardNo]);
            $ben = $stmt->fetch();
            if (!$ben) throw new RuntimeException("AssureCare Card Not Found.");

            $year = (int)date('Y');
            $wStmt = $pdo->prepare("SELECT * FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ?");
            $wStmt->execute([$cardNo, $year]);
            $w = $wStmt->fetch();

            $baseRem = $w ? ((float)$w['base_5l_limit'] - (float)$w['base_5l_used']) : 0;
            $extraRem = ($w && (int)$w['extra_2_5l_approved'] === 1) ? ((float)$w['extra_2_5l_limit'] - (float)$w['extra_2_5l_used']) : 0;

            $visitInfo = null;
            if ($provId > 0) {
                $pStmt = $pdo->prepare("SELECT * FROM providers_registry WHERE provider_id = ?");
                $pStmt->execute([$provId]);
                $prov = $pStmt->fetch();

                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_prescriptions WHERE provider_id = ? AND appointment_date = CURDATE()");
                $cntStmt->execute([$provId]);
                $todayCount = (int)$cntStmt->fetchColumn();

                $revStmt = $pdo->prepare(
                    "SELECT * FROM appointments_prescriptions
                     WHERE assurecare_card_no = ? AND provider_id = ? AND revisit_valid_until >= CURDATE()
                     ORDER BY rx_id DESC LIMIT 1"
                );
                $revStmt->execute([$cardNo, $provId]);
                $activeVisit = $revStmt->fetch();

                $visitInfo = [
                    'today_patients' => $todayCount,
                    'max_patients_per_day' => (int)$prov['max_patients_per_day'],
                    'is_free_revisit' => $activeVisit ? true : false,
                    'applicable_fee' => $activeVisit ? 0.00 : (float)$prov['new_visit_fee'],
                    'valid_until' => $activeVisit ? $activeVisit['revisit_valid_until'] : date('Y-m-d', strtotime("+{$prov['revisit_validity_days']} days")),
                    'revisit_days' => (int)$prov['revisit_validity_days']
                ];
            }

            $txStmt = $pdo->prepare(
                "SELECT t.*, p.org_or_doctor_name, h.org_or_doctor_name AS parent_hospital_name
                 FROM transactions_ledger t
                 LEFT JOIN providers_registry p ON t.provider_id = p.provider_id
                 LEFT JOIN providers_registry h ON t.parent_hospital_id = h.provider_id
                 WHERE t.assurecare_card_no = ? ORDER BY t.tx_id ASC"
            );
            $txStmt->execute([$cardNo]);
            $txs = $txStmt->fetchAll();

            $appStmt = $pdo->prepare(
                "SELECT a.*, p.org_or_doctor_name FROM appointments_prescriptions a
                 JOIN providers_registry p ON a.provider_id = p.provider_id
                 WHERE a.assurecare_card_no = ? ORDER BY a.rx_id DESC"
            );
            $appStmt->execute([$cardNo]);
            $apps = $appStmt->fetchAll();

            echo json_encode([
                'status' => 'OK',
                'beneficiary' => $ben,
                'wallet' => $w,
                'base_remaining' => $baseRem,
                'extra_remaining' => $extraRem,
                'visit_info' => $visitInfo,
                'transactions' => $txs,
                'appointments' => $apps
            ]);
            break;

        case 'create_rx':
            $cardNo = trim($_POST['card_no'] ?? '');
            $provId = (int)($_POST['provider_id'] ?? 0);
            $diag = trim($_POST['diagnosis'] ?? '');
            $tests = trim($_POST['tests'] ?? '');
            $meds = trim($_POST['medicines'] ?? '');
            $followup = $_POST['followup_date'] ?: null;
            $otp = trim($_POST['otp'] ?? '123456');

            $bStmt = $pdo->prepare("SELECT * FROM users_beneficiaries WHERE assurecare_card_no = ?");
            $bStmt->execute([$cardNo]);
            $ben = $bStmt->fetch();
            if (!$ben || $ben['account_status'] !== 'ACTIVE') {
                throw new RuntimeException("Patient AssureCare Card is FROZEN or DEACTIVATED.");
            }

            $pStmt = $pdo->prepare("SELECT * FROM providers_registry WHERE provider_id = ?");
            $pStmt->execute([$provId]);
            $prov = $pStmt->fetch();
            if (!$prov || (int)$prov['is_verified'] !== 1 || $prov['account_status'] !== 'ACTIVE') {
                throw new RuntimeException("Provider is not Govt Verified or is currently Frozen.");
            }

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments_prescriptions WHERE provider_id = ? AND appointment_date = CURDATE()");
            $cntStmt->execute([$provId]);
            $todayCount = (int)$cntStmt->fetchColumn();
            if ($todayCount >= (int)$prov['max_patients_per_day']) {
                throw new RuntimeException("DAILY PATIENT LIMIT REACHED: Max {$prov['max_patients_per_day']} patients/day allowed.");
            }

            $revStmt = $pdo->prepare(
                "SELECT * FROM appointments_prescriptions
                 WHERE assurecare_card_no = ? AND provider_id = ? AND revisit_valid_until >= CURDATE()
                 ORDER BY rx_id DESC LIMIT 1"
            );
            $revStmt->execute([$cardNo, $provId]);
            $activeVisit = $revStmt->fetch();

            $visitType = $activeVisit ? 'FREE_REVISIT' : 'NEW_VISIT';
            $feeToCharge = $activeVisit ? 0.00 : (float)$prov['new_visit_fee'];
            $validUntil = $activeVisit ? $activeVisit['revisit_valid_until'] : date('Y-m-d', strtotime("+{$prov['revisit_validity_days']} days"));
            $year = (int)date('Y');

            [$parentHospId, $hospShare, $provShare] = computePayoutSplit($pdo, $prov, $feeToCharge);

            $pdo->beginTransaction();
            $ins = $pdo->prepare(
                "INSERT INTO appointments_prescriptions
                (assurecare_card_no, patient_name_locked, provider_id, visit_type, fee_charged, appointment_date, revisit_valid_until, next_followup_date, diagnosis_summary, prescribed_tests, prescribed_medicines)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)"
            );
            $ins->execute([$cardNo, $ben['full_name'], $provId, $visitType, $feeToCharge, $validUntil, $followup, $diag, $tests, $meds]);
            $rxId = (int)$pdo->lastInsertId();

            if ($feeToCharge > 0) {
                if ($otp !== '123456') throw new RuntimeException("Valid Patient Consent OTP (123456) required for New Visit Fee.");
                $wStmt = $pdo->prepare("SELECT * FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ? FOR UPDATE");
                $wStmt->execute([$cardNo, $year]);
                $w = $wStmt->fetch();
                $baseRem = (float)$w['base_5l_limit'] - (float)$w['base_5l_used'];
                $extraRem = ((int)$w['extra_2_5l_approved'] === 1) ? ((float)$w['extra_2_5l_limit'] - (float)$w['extra_2_5l_used']) : 0.0;
                if ($feeToCharge > ($baseRem + $extraRem)) throw new RuntimeException("Insufficient AssureCare Balance.");

                $fromBase = min($feeToCharge, $baseRem);
                $fromExtra = $feeToCharge - $fromBase;
                if ($fromBase > 0) {
                    appendImmutableLedger($pdo, $cardNo, $provId, $parentHospId, $rxId, 'DOCTOR_HOSPITAL', 'DEBIT', 'BASE_5L_ZERO_INT', $fromBase, $hospShare, $provShare, "New Visit Fee (Valid till $validUntil)", $year);
                }
                if ($fromExtra > 0) {
                    appendImmutableLedger($pdo, $cardNo, $provId, $parentHospId, $rxId, 'DOCTOR_HOSPITAL', 'DEBIT', 'EXTRA_2_5L_INTEREST', $fromExtra, $hospShare, $provShare, "New Visit Fee [Extra Credit]", $year);
                }
                $pdo->prepare("UPDATE credit_wallets SET base_5l_used = base_5l_used + ?, extra_2_5l_used = extra_2_5l_used + ? WHERE wallet_id = ?")
                    ->execute([$fromBase, $fromExtra, $w['wallet_id']]);
            }

            $pdo->commit();
            echo json_encode([
                'status' => 'OK',
                'rx_id' => $rxId,
                'visit_type' => $visitType,
                'fee_charged' => $feeToCharge,
                'hospital_share' => $hospShare,
                'provider_share' => $provShare,
                'valid_until' => $validUntil,
                'patient_locked' => $ben['full_name']
            ]);
            break;

        case 'process_billing':
            $cardNo = trim($_POST['card_no'] ?? '');
            $provId = (int)($_POST['provider_id'] ?? 0);
            $rxId = !empty($_POST['rx_id']) ? (int)$_POST['rx_id'] : null;
            $section = $_POST['section_type'] ?? 'DOCTOR_HOSPITAL';
            $amount = (float)($_POST['amount'] ?? 0);
            $desc = trim($_POST['description'] ?? 'Medical Service');
            $otp = trim($_POST['otp'] ?? '');
            $year = (int)date('Y');

            if ($otp !== '123456') throw new RuntimeException("Invalid Beneficiary OTP! (Demo OTP is 123456)");
            if ($amount <= 0) throw new RuntimeException("Billing amount must be greater than 0.");

            $bStmt = $pdo->prepare("SELECT * FROM users_beneficiaries WHERE assurecare_card_no = ?");
            $bStmt->execute([$cardNo]);
            $ben = $bStmt->fetch();
            if (!$ben || $ben['account_status'] !== 'ACTIVE') {
                throw new RuntimeException("TRANSACTION BLOCKED: AssureCare Card is " . ($ben['account_status'] ?? 'INVALID'));
            }

            $pStmt = $pdo->prepare("SELECT * FROM providers_registry WHERE provider_id = ?");
            $pStmt->execute([$provId]);
            $prov = $pStmt->fetch();
            if (!$prov || (int)$prov['is_verified'] !== 1 || $prov['account_status'] !== 'ACTIVE') {
                throw new RuntimeException("TRANSACTION BLOCKED: Provider unverified or Frozen by Admin.");
            }

            if (in_array($section, ['DIAGNOSTIC_LAB', 'PHARMACY'], true)) {
                if (!$rxId) throw new RuntimeException("Valid Doctor Prescription ID (Rx#) is required for Lab/Pharmacy billing.");
                $rCheck = $pdo->prepare("SELECT rx_id FROM appointments_prescriptions WHERE rx_id = ? AND assurecare_card_no = ?");
                $rCheck->execute([$rxId, $cardNo]);
                if (!$rCheck->fetchColumn()) throw new RuntimeException("Rx ID #$rxId does not belong to AssureCare Card $cardNo.");
            }

            [$parentHospId, $hospShare, $provShare] = computePayoutSplit($pdo, $prov, $amount);

            $pdo->beginTransaction();
            $wStmt = $pdo->prepare("SELECT * FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ? FOR UPDATE");
            $wStmt->execute([$cardNo, $year]);
            $w = $wStmt->fetch();
            if (!$w) throw new RuntimeException("No active credit wallet found for $year.");

            $baseRem = (float)$w['base_5l_limit'] - (float)$w['base_5l_used'];
            $extraRem = ((int)$w['extra_2_5l_approved'] === 1) ? ((float)$w['extra_2_5l_limit'] - (float)$w['extra_2_5l_used']) : 0.0;
            if ($amount > ($baseRem + $extraRem)) {
                throw new RuntimeException("Insufficient AssureCare Balance!");
            }

            $fromBase = min($amount, $baseRem);
            $fromExtra = $amount - $fromBase;
            if ($fromBase > 0) appendImmutableLedger($pdo, $cardNo, $provId, $parentHospId, $rxId, $section, 'DEBIT', 'BASE_5L_ZERO_INT', $fromBase, $hospShare, $provShare, "$desc [0% Base]", $year);
            if ($fromExtra > 0) appendImmutableLedger($pdo, $cardNo, $provId, $parentHospId, $rxId, $section, 'DEBIT', 'EXTRA_2_5L_INTEREST', $fromExtra, $hospShare, $provShare, "$desc [Emergency Credit]", $year);

            $pdo->prepare("UPDATE credit_wallets SET base_5l_used = base_5l_used + ?, extra_2_5l_used = extra_2_5l_used + ? WHERE wallet_id = ?")
                ->execute([$fromBase, $fromExtra, $w['wallet_id']]);
            $pdo->commit();

            $splitMsg = $parentHospId
                ? "Split Payout -> Hospital Share: Rs. $hospShare | Provider Share: Rs. $provShare (Bank: {$prov['bank_account_no']})"
                : "100% Direct Payout -> Bank A/C {$prov['bank_account_no']}";
            echo json_encode(['status' => 'OK', 'message' => "Rs. $amount billed to {$ben['full_name']}. $splitMsg"]);
            break;

        case 'apply_extra_credit':
            $cardNo = trim($_POST['card_no'] ?? '');
            $year = (int)date('Y');
            $wStmt = $pdo->prepare("SELECT * FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ?");
            $wStmt->execute([$cardNo, $year]);
            $w = $wStmt->fetch();
            if (!$w) throw new RuntimeException("Wallet not found.");
            if ((float)$w['base_5l_used'] < (float)$w['base_5l_limit']) throw new RuntimeException("Base Rs. 5,00,000 limit must be exhausted first.");
            if ((int)$w['extra_2_5l_approved'] === 1) throw new RuntimeException("Emergency Extra Credit already availed once this year.");

            $pdo->prepare("UPDATE credit_wallets SET extra_2_5l_approved = 1 WHERE wallet_id = ?")->execute([$w['wallet_id']]);
            appendImmutableLedger($pdo, $cardNo, null, null, null, 'SYSTEM_CREDIT', 'CREDIT', 'EXTRA_2_5L_INTEREST', 250000.00, 0.00, 0.00, "Emergency +Rs. 2,50,000 Credit Buffer Activated (1x/Yr)", $year);
            echo json_encode(['status' => 'OK', 'message' => '+Rs. 2,50,000 Emergency Credit Activated!']);
            break;

        case 'simulate_jan1_rollover':
            $cardNo = trim($_POST['card_no'] ?? '');
            $currYear = (int)date('Y');
            $nextYear = $currYear + 1;

            $wStmt = $pdo->prepare("SELECT * FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ?");
            $wStmt->execute([$cardNo, $currYear]);
            $prev = $wStmt->fetch();

            $chkNext = $pdo->prepare("SELECT wallet_id FROM credit_wallets WHERE assurecare_card_no = ? AND calendar_year = ?");
            $chkNext->execute([$cardNo, $nextYear]);
            if ($chkNext->fetchColumn()) throw new RuntimeException("1st-Jan-$nextYear reconciliation already executed.");

            $unpaid = $prev ? max(0.0, (float)$prev['extra_2_5l_used'] - (float)$prev['extra_repaid_principal']) : 0.0;
            $interest = round($unpaid * ((float)($prev['interest_rate_pct'] ?? 5.0) / 100.0), 2);
            $recovery = $unpaid + $interest;

            $pdo->beginTransaction();
            appendImmutableLedger($pdo, $cardNo, null, null, null, 'SYSTEM_CREDIT', 'CREDIT', 'BASE_5L_ZERO_INT', 500000.00, 0.00, 0.00, "New Year Annual NHAS Limit Credited (01-Jan-$nextYear)", $nextYear);
            if ($recovery > 0) {
                appendImmutableLedger($pdo, $cardNo, null, null, null, 'SYSTEM_CREDIT', 'DEBIT', 'JAN1_AUTO_RECONCILE', $recovery, 0.00, 0.00, "Auto-Adjusted: FY$currYear Extra Credit (Rs. $unpaid) + Interest (Rs. $interest)", $nextYear);
            }
            $pdo->prepare("INSERT INTO credit_wallets (assurecare_card_no, calendar_year, base_5l_limit, base_5l_used) VALUES (?, ?, 500000.00, ?)")
                ->execute([$cardNo, $nextYear, $recovery]);
            $pdo->commit();

            echo json_encode(['status' => 'OK', 'message' => "01-Jan-$nextYear Rollover Complete! Credited +Rs. 5,00,000, Auto-Debited -Rs. $recovery."]);
            break;

        case 'admin_bulk_action':
            $targetType = $_POST['target_type'] ?? 'BENEFICIARY';
            $bulkAction = $_POST['bulk_action'] ?? '';
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            $reason = trim($_POST['reason'] ?? 'Regulatory Action');
            $rate = (float)($_POST['interest_rate'] ?? 5.0);

            if (empty($ids)) throw new RuntimeException("Select at least one record.");
            $ph = implode(',', array_fill(0, count($ids), '?'));

            if ($targetType === 'PROVIDER') {
                if ($bulkAction === 'VERIFY') $pdo->prepare("UPDATE providers_registry SET is_verified = 1 WHERE provider_id IN ($ph)")->execute($ids);
                elseif ($bulkAction === 'FREEZE') $pdo->prepare("UPDATE providers_registry SET account_status = 'FROZEN', hold_reason = ? WHERE provider_id IN ($ph)")->execute(array_merge([$reason], $ids));
                elseif ($bulkAction === 'UNFREEZE') $pdo->prepare("UPDATE providers_registry SET account_status = 'ACTIVE', hold_reason = NULL WHERE provider_id IN ($ph)")->execute($ids);
            } else {
                if ($bulkAction === 'FREEZE') $pdo->prepare("UPDATE users_beneficiaries SET account_status = 'FROZEN', freeze_reason = ? WHERE assurecare_card_no IN ($ph)")->execute(array_merge([$reason], $ids));
                elseif ($bulkAction === 'UNFREEZE') $pdo->prepare("UPDATE users_beneficiaries SET account_status = 'ACTIVE', freeze_reason = NULL WHERE assurecare_card_no IN ($ph)")->execute($ids);
                elseif ($bulkAction === 'INTEREST') $pdo->prepare("UPDATE credit_wallets SET interest_rate_pct = ? WHERE assurecare_card_no IN ($ph)")->execute(array_merge([$rate], $ids));
            }
            echo json_encode(['status' => 'OK', 'message' => 'Governance Action Executed Successfully!']);
            break;

        case 'request_deactivation':
            $cardNo = trim($_POST['card_no'] ?? '');
            $certName = trim($_POST['cert_filename'] ?? 'death_certificate.pdf');
            $year = (int)date('Y');
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM deactivation_requests WHERE assurecare_card_no = ? AND request_year = ?");
            $cnt->execute([$cardNo, $year]);
            $attempts = (int)$cnt->fetchColumn();
            if ($attempts >= 2) throw new RuntimeException("Annual Limit Reached: Maximum 2 deactivation requests per year.");
            $pdo->prepare("INSERT INTO deactivation_requests (assurecare_card_no, request_year, attempt_no, death_cert_filename) VALUES (?, ?, ?, ?)")
                ->execute([$cardNo, $year, $attempts + 1, $certName]);
            echo json_encode(['status' => 'OK', 'message' => "Deactivation Request #" . ($attempts + 1) . " submitted."]);
            break;

        case 'process_deactivation':
            $reqId = (int)($_POST['req_id'] ?? 0);
            $decision = $_POST['decision'] ?? 'APPROVED';
            $reason = trim($_POST['reason'] ?? 'Verified');
            $rStmt = $pdo->prepare("SELECT * FROM deactivation_requests WHERE req_id = ?");
            $rStmt->execute([$reqId]);
            $req = $rStmt->fetch();
            $pdo->prepare("UPDATE deactivation_requests SET status = ?, admin_reason = ? WHERE req_id = ?")->execute([$decision, $reason, $reqId]);
            if ($decision === 'APPROVED' && $req) {
                $pdo->prepare("UPDATE users_beneficiaries SET account_status = 'DEACTIVATED' WHERE assurecare_card_no = ?")->execute([$req['assurecare_card_no']]);
            }
            echo json_encode(['status' => 'OK', 'message' => "Deactivation Request $decision."]);
            break;

        case 'submit_complaint':
            $cardNo = trim($_POST['card_no'] ?? '');
            $accused = trim($_POST['accused_provider'] ?? '');
            $subj = trim($_POST['subject'] ?? '');
            $msg = trim($_POST['message'] ?? '');
            $ev = trim($_POST['evidence'] ?? 'evidence_photo.jpg');
            $bStmt = $pdo->prepare("SELECT full_name FROM users_beneficiaries WHERE assurecare_card_no = ?");
            $bStmt->execute([$cardNo]);
            $lockedName = $bStmt->fetchColumn();
            if (!$lockedName) throw new RuntimeException("Invalid AssureCare Card.");
            $trackNo = 'CMP-' . date('Y') . '-' . strtoupper(substr(md5($cardNo . microtime()), 0, 6));
            $pdo->prepare("INSERT INTO grievances_complaints (tracking_no, assurecare_card_no, complainant_name_locked, accused_provider_card, subject, message, evidence_filename) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$trackNo, $cardNo, $lockedName, $accused, $subj, $msg, $ev]);
            echo json_encode(['status' => 'OK', 'tracking_no' => $trackNo]);
            break;

        case 'update_complaint_stage':
            $cid = (int)($_POST['complaint_id'] ?? 0);
            $stage = $_POST['stage'] ?? 'UNDER_SURVEILLANCE';
            $pdo->prepare("UPDATE grievances_complaints SET stage = ? WHERE complaint_id = ?")->execute([$stage, $cid]);
            echo json_encode(['status' => 'OK', 'message' => "Stage updated to $stage"]);
            break;

        case 'get_system_state':
            $fromYear = (int)($_GET['from_year'] ?? 2026);
            $toYear = (int)($_GET['to_year'] ?? 2027);
            $sort = (strtoupper($_GET['sort'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

            $tStmt = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN credit_source='BASE_5L_ZERO_INT' AND entry_direction='DEBIT' THEN amount ELSE 0 END),0) AS zero_int,
                    COALESCE(SUM(CASE WHEN credit_source='EXTRA_2_5L_INTEREST' AND entry_direction='DEBIT' THEN amount ELSE 0 END),0) AS with_int,
                    COALESCE(SUM(CASE WHEN credit_source IN ('JAN1_AUTO_RECONCILE','DIRECT_REPAY') AND entry_direction='DEBIT' THEN amount ELSE 0 END),0) AS recovered
                 FROM transactions_ledger WHERE tx_year BETWEEN ? AND ?"
            );
            $tStmt->execute([$fromYear, $toYear]);
            $tr = $tStmt->fetch();
            $d0 = (float)$tr['zero_int'];
            $pExt = (float)$tr['with_int'];
            $rec = (float)$tr['recovered'];
            $pend = max(0.0, $pExt - $rec);
            $netInv = $d0 + ($pExt - $rec);

            $bens = $pdo->query("SELECT b.*, w.base_5l_used, w.extra_2_5l_approved, w.extra_2_5l_used, w.interest_rate_pct FROM users_beneficiaries b LEFT JOIN credit_wallets w ON b.assurecare_card_no=w.assurecare_card_no AND w.calendar_year=2026 ORDER BY b.beneficiary_id $sort")->fetchAll();

            $provs = $pdo->query(
                "SELECT p.*,
                    COALESCE((SELECT SUM(provider_share_amount) FROM transactions_ledger t WHERE t.provider_id=p.provider_id AND t.entry_direction='DEBIT' AND t.tx_year BETWEEN $fromYear AND $toYear),0) AS own_unit_earnings,
                    COALESCE((SELECT SUM(hospital_share_amount) FROM transactions_ledger t WHERE t.parent_hospital_id=p.provider_id AND t.entry_direction='DEBIT' AND t.tx_year BETWEEN $fromYear AND $toYear),0) AS affiliated_hosp_earnings,
                    COALESCE((SELECT COUNT(DISTINCT assurecare_card_no) FROM transactions_ledger t WHERE (t.provider_id=p.provider_id OR t.parent_hospital_id=p.provider_id) AND t.entry_direction='DEBIT'),0) AS patients_served
                 FROM providers_registry p ORDER BY p.provider_id $sort"
            )->fetchAll();

            foreach ($provs as &$pr) {
                $pr['total_earnings'] = (float)$pr['own_unit_earnings'] + (float)$pr['affiliated_hosp_earnings'];
            }

            $txs = $pdo->query(
                "SELECT t.*, p.org_or_doctor_name, h.org_or_doctor_name AS parent_hospital_name
                 FROM transactions_ledger t
                 LEFT JOIN providers_registry p ON t.provider_id=p.provider_id
                 LEFT JOIN providers_registry h ON t.parent_hospital_id=h.provider_id
                 WHERE t.tx_year BETWEEN $fromYear AND $toYear ORDER BY t.tx_id $sort"
            )->fetchAll();

            $cmps = $pdo->query("SELECT * FROM grievances_complaints ORDER BY complaint_id DESC")->fetchAll();
            $deacts = $pdo->query("SELECT * FROM deactivation_requests ORDER BY req_id DESC")->fetchAll();

            echo json_encode([
                'status' => 'OK',
                'treasury' => ['zero_int' => $d0, 'with_int' => $pExt, 'recovered' => $rec, 'pending' => $pend, 'net_investment' => $netInv],
                'beneficiaries' => $bens, 'providers' => $provs, 'transactions' => $txs, 'complaints' => $cmps, 'deactivations' => $deacts
            ]);
            break;

        default:
            throw new RuntimeException("Invalid Action Requested.");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
}
?>