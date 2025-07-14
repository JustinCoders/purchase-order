<?php
session_start();

date_default_timezone_set('Asia/Manila');
$success_message = '';
$error_message = '';

if (!isset($_SESSION['form_token'])) {
  $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['success_message'])) {
  $success_message = $_SESSION['success_message'];
  unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
  $error_message = $_SESSION['error_message'];
  unset($_SESSION['error_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['savePurchaseOrder'])) {
  require_once 'db.php';

  if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
    $_SESSION['error_message'] = 'Invalid form submission. Please try again.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
  }

  $_SESSION['form_token'] = bin2hex(random_bytes(32));

  $errors = [];

  $descriptions = $_POST['description'] ?? [];
  $qtys = $_POST['qty'] ?? [];
  $unit_prices = $_POST['unit_price'] ?? [];

  if (empty($descriptions) || empty($qtys) || empty($unit_prices)) {
    $errors[] = "At least one item is required";
  } else {
    foreach ($descriptions as $index => $description) {
      if (empty($description) || empty($qtys[$index]) || empty($unit_prices[$index])) {
        $errors[] = "All item fields (description, quantity, unit price) are required";
        break;
      }
    }
  }

  if (!empty($errors)) {
    $_SESSION['error_message'] = implode('<br>', $errors);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
  }

  $result = $conn->query("SELECT COUNT(*) as count FROM purchase_orders");
  if ($result && $row = $result->fetch_assoc()) {
    $po_number = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
  } else {
    $po_number = '0001';
  }

  $company_name = $conn->real_escape_string($_POST['companyName'] ?? '');
  $street_address = $conn->real_escape_string($_POST['streetAddress'] ?? '');
  $city = $conn->real_escape_string($_POST['city'] ?? '');
  $contact_number = $conn->real_escape_string($_POST['phoneNumber'] ?? '');
  $requisitioner = $conn->real_escape_string($_POST['requisitioner'] ?? '');
  $ship_via = $conn->real_escape_string($_POST['ship_via'] ?? '');
  $fob = $conn->real_escape_string($_POST['fob'] ?? '');
  $shipping_terms = $conn->real_escape_string($_POST['shipping_terms'] ?? '');
  $currency = $conn->real_escape_string($_POST['currency'] ?? 'PHP');
  $subtotal = floatval($_POST['grandTotal'] ?? 0);
  $tax = floatval($_POST['taxInput'] ?? 0);
  $shipping = floatval($_POST['shippingInput'] ?? 0);
  $other = floatval($_POST['otherInput'] ?? 0);
  $total = floatval($_POST['totalAmount'] ?? 0);
  $edit_id = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? intval($_POST['edit_id']) : null;

  if ($edit_id) {
    $po_number = $conn->real_escape_string($_POST['poNumber'] ?? '');
    $sql = "UPDATE purchase_orders SET 
                po_number=?, 
                company_name=?, 
                street_address=?, 
                city=?, 
                contact_number=?, 
                requisitioner=?, 
                ship_via=?, 
                fob=?, 
                shipping_terms=?, 
                currency=?, 
                subtotal=?, 
                tax=?, 
                shipping=?, 
                other=?, 
                total=? 
                WHERE id=?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param(
        'sssssssssssddddi',
        $po_number,
        $company_name,
        $street_address,
        $city,
        $contact_number,
        $requisitioner,
        $ship_via,
        $fob,
        $shipping_terms,
        $currency,
        $subtotal,
        $tax,
        $shipping,
        $other,
        $total,
        $edit_id
      );

      if ($stmt->execute()) {
        $conn->query("DELETE FROM purchase_order_items WHERE purchase_order_id = $edit_id");

        $descriptions = $_POST['description'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];
        $totals = $_POST['total'] ?? [];

        $item_sql = "INSERT INTO purchase_order_items (purchase_order_id, item_no, description, qty, unit_price, total) VALUES (?,?,?,?,?,?)";
        $item_stmt = $conn->prepare($item_sql);

        if ($item_stmt) {
          for ($i = 0; $i < count($descriptions); $i++) {
            if (empty($descriptions[$i])) continue;

            $desc = $conn->real_escape_string($descriptions[$i]);
            $qty = intval($qtys[$i]);
            $unit_price = floatval($unit_prices[$i]);
            $item_total = floatval($totals[$i]);
            $item_no = $i + 1;

            $item_stmt->bind_param(
              'iisidd',
              $edit_id,
              $item_no,
              $desc,
              $qty,
              $unit_price,
              $item_total
            );
            $item_stmt->execute();
          }
          $_SESSION['success_message'] = 'Purchase order updated successfully!';
        } else {
          $_SESSION['error_message'] = 'Failed to prepare item update statement.';
        }
        $item_stmt->close();
      } else {
        $_SESSION['error_message'] = 'Failed to update purchase order.';
      }
      $stmt->close();
    } else {
      $_SESSION['error_message'] = 'Failed to prepare purchase order update statement.';
    }
  } else {
    $sql = "INSERT INTO purchase_orders (po_number, company_name, street_address, city, contact_number, requisitioner, ship_via, fob, shipping_terms, currency, subtotal, tax, shipping, other, total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $stmt->bind_param('sssssssssssdddd', $po_number, $company_name, $street_address, $city, $contact_number, $requisitioner, $ship_via, $fob, $shipping_terms, $currency, $subtotal, $tax, $shipping, $other, $total);
      if ($stmt->execute()) {
        $purchase_order_id = $stmt->insert_id;
        $descriptions = $_POST['description'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];
        $totals = $_POST['total'] ?? [];
        $item_sql = "INSERT INTO purchase_order_items (purchase_order_id, item_no, description, qty, unit_price, total) VALUES (?,?,?,?,?,?)";
        $item_stmt = $conn->prepare($item_sql);
        if ($item_stmt) {
          for ($i = 0; $i < count($descriptions); $i++) {
            $desc = $conn->real_escape_string($descriptions[$i]);
            $qty = intval($qtys[$i]);
            $unit_price = floatval($unit_prices[$i]);
            $item_total = floatval($totals[$i]);
            $item_no = $i + 1;
            $item_stmt->bind_param('iisidd', $purchase_order_id, $item_no, $desc, $qty, $unit_price, $item_total);
            $item_stmt->execute();
          }
          $_SESSION['success_message'] = 'Purchase order saved successfully!';
        } else {
          $_SESSION['error_message'] = 'Failed to prepare item insert statement.';
        }
      } else {
        $_SESSION['error_message'] = 'Failed to save purchase order.';
      }
      $stmt->close();
    } else {
      $_SESSION['error_message'] = 'Failed to prepare purchase order insert statement.';
    }
  }

  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletePurchaseOrder'])) {
  require_once 'db.php';
  $id = intval($_POST['id']);

  $conn->query("DELETE FROM purchase_order_items WHERE purchase_order_id = $id");

  if ($conn->query("DELETE FROM purchase_orders WHERE id = $id")) {
    $result = $conn->query("SELECT id, po_number FROM purchase_orders ORDER BY id ASC");
    $orders = [];
    while ($row = $result->fetch_assoc()) {
      $orders[] = $row;
    }

    foreach ($orders as $index => $order) {
      $new_po_number = str_pad($index + 1, 4, '0', STR_PAD_LEFT);
      $conn->query("UPDATE purchase_orders SET po_number = '$new_po_number' WHERE id = {$order['id']}");
    }

    $_SESSION['success_message'] = 'Purchase order deleted successfully!';
  } else {
    $_SESSION['error_message'] = 'Failed to delete purchase order.';
  }

  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}

require_once 'db.php';
$companies = [];
$result = $conn->query("SELECT * FROM purchase_orders ORDER BY id DESC");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $companies[] = $row;
  }
}

$next_po_number = '0001';
$result = $conn->query("SELECT COUNT(*) as count FROM purchase_orders");
if ($result && $row = $result->fetch_assoc()) {
  $next_po_number = str_pad($row['count'] + 1, 4, '0', STR_PAD_LEFT);
}

if (isset($_GET['get_items'])) {
  ob_start();
  header('Content-Type: application/json');
  require_once 'db.php';
  $id = intval($_GET['get_items']);
  $items = [];
  try {
    if (!$conn) {
      throw new Exception("Database connection failed");
    }
    $tables_check = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
    if ($tables_check->num_rows === 0) {
      throw new Exception("purchase_orders table does not exist");
    }
    $tables_check = $conn->query("SHOW TABLES LIKE 'purchase_order_items'");
    if ($tables_check->num_rows === 0) {
      throw new Exception("purchase_order_items table does not exist");
    }
    $check_sql = "SELECT id FROM purchase_orders WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) throw new Exception("Failed to prepare check statement: " . $conn->error);
    $check_stmt->bind_param('i', $id);
    if (!$check_stmt->execute()) throw new Exception("Failed to execute check statement: " . $check_stmt->error);
    $result = $check_stmt->get_result();
    if ($result->num_rows === 0) {
      echo json_encode([]);
      $check_stmt->close();
      $conn->close();
      ob_end_flush();
      exit;
    }
    $sql = "SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY item_no ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Failed to prepare items statement: " . $conn->error);
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception("Failed to execute items statement: " . $stmt->error);
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
      $items[] = $row;
    }
    $stmt->close();
    $check_stmt->close();
    echo json_encode($items);
    ob_end_flush();
  } catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  $conn->close();
  exit;
}

if (isset($_GET['get_view_data'])) {
  ob_start();
  header('Content-Type: application/json');
  require_once 'db.php';
  $id = intval($_GET['get_view_data']);

  try {
    $sql = "SELECT * FROM purchase_orders WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Failed to prepare statement: " . $conn->error);

    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception("Failed to execute statement: " . $stmt->error);

    $result = $stmt->get_result();
    $purchase_order = $result->fetch_assoc();

    $items_sql = "SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY item_no ASC";
    $items_stmt = $conn->prepare($items_sql);
    if (!$items_stmt) throw new Exception("Failed to prepare items statement: " . $conn->error);

    $items_stmt->bind_param('i', $id);
    if (!$items_stmt->execute()) throw new Exception("Failed to execute items statement: " . $items_stmt->error);

    $items_result = $items_stmt->get_result();
    $items = [];
    while ($row = $items_result->fetch_assoc()) {
      $items[] = $row;
    }

    $response = [
      'purchase_order' => $purchase_order,
      'items' => $items
    ];

    echo json_encode($response);
    ob_end_flush();
  } catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  $conn->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggleCancelPO'])) {
  require_once 'db.php';
  $id = intval($_POST['id']);
  $cancel = intval($_POST['cancel']);

  $stmt = $conn->prepare("UPDATE purchase_orders SET is_cancelled = ? WHERE id = ?");
  $stmt->bind_param('ii', $cancel, $id);
  $success = $stmt->execute();
  $stmt->close();
  $conn->close();

  echo json_encode(['success' => $success]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet" />
  <title>JLQ Purchase Order</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous" />
  <link rel="icon" href="img/jlq-logo.png" type="image/png" draggable="false" loading="lazy" />
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: "Poppins", sans-serif;
    }

    body {
      background-color: #f4f7fc;
      display: flex;
      min-height: 100vh;
      margin: 0;
      overflow: hidden;
    }

    .header {
      background: #1a2a44;
      color: #fff;
      width: 280px;
      height: 100vh;
      padding: 20px;
      position: fixed;
      top: 0;
      left: 0;
      transition: width 0.3s ease;
      z-index: 1000;
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 15px;
      padding-bottom: 15px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .jlq-logo {
      width: 50px;
      border-radius: 8px;
    }

    .logo-title h1 {
      font-size: 1.8rem;
      font-weight: 600;
    }

    .jlq-desc {
      font-size: 0.75rem;
      color: #a0aec0;
      margin-top: 5px;
      display: block;
    }

    .navigation-bar {
      margin-top: 20px;
    }

    .nav-btn {
      text-decoration: none;
      color: #e2e8f0;
      background-color: transparent;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 15px;
      font-size: 0.95rem;
      border-radius: 8px;
      transition: background-color 0.3s, color 0.3s;
      margin-bottom: 10px;
    }

    .nav-btn:hover,
    .nav-btn.active {
      background-color: #3b82f6;
      color: #fff;
    }

    .nav-btn i {
      font-size: 1.1rem;
    }

    .content {
      margin-left: 280px;
      padding: 30px;
      width: calc(100% - 280px);
      height: 100vh;
      background-color: #f4f7fc;
      display: flex;
      flex-direction: column;
    }

    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
      padding: 20px;
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .table-responsive {
      flex: 1;
      overflow-y: auto;
      margin-bottom: 0;
    }

    .table {
      margin-bottom: 0;
    }

    .table thead {
      background: #1a2a44;
      color: #fff;
    }

    .table th,
    .table td {
      padding: 15px;
      vertical-align: middle;
    }

    .table tbody tr {
      transition: background-color 0.2s;
    }

    .table tbody tr:hover {
      background-color: #f1f5f9;
    }

    .btn-sm {
      padding: 8px 12px;
      font-size: 0.85rem;
      border-radius: 6px;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-sm:hover {
      transform: translateY(-2px);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .hamburger {
      display: none;
      font-size: 1.5rem;
      color: #fff;
      cursor: pointer;
      position: absolute;
      top: 20px;
      right: 20px;
    }

    textarea {
      resize: none;
    }

    .form-label {
      margin-bottom: 0.2rem;
      font-size: 0.9rem;
    }

    .form-control {
      border: 2px solid #999;
      box-shadow: none;
    }

    .form-control.is-invalid {
      border-color: #dc3545;
    }

    .invalid-feedback {
      color: #dc3545;
      font-size: 0.85rem;
      margin-top: 0.25rem;
      width: auto;
    }

    .no-data {
      text-align: center;
      color: #6c757d;
      font-style: italic;
    }

    .alert {
      margin-bottom: 20px;
    }

    .success-alert {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1050;
      animation: slideIn 0.5s ease-in-out;
      opacity: 1;
      max-width: 367px;
    }

    .custom-loading::after {
      content: '';
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid #fff;
      border-top-color: transparent;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-left: 8px;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    .error-alert {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1050;
      animation: slideIn 0.5s ease-in-out;
      opacity: 1;
      max-width: 300px;
    }

    .modal-content {
      height: 600px;
      overflow: hidden;
      overflow-y: auto;
    }

    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    @keyframes fadeOut {
      from {
        opacity: 1;
      }

      to {
        opacity: 0;
        display: none;
      }
    }

    .cancelled-row,
    .cancelled-row td {
      background-color: #ffe066 !important;
    }

    .cancelled-btn {
      background-color: #ffe066 !important;
      color: #856404 !important;
      border-color: #ffe066 !important;
    }


    @media (max-width: 768px) {
      .header {
        width: 0;
        overflow: hidden;
      }

      .header.active {
        width: 280px;
      }

      .content {
        margin-left: 0;
        width: 100%;
      }

      .hamburger {
        display: block;
      }

      .success-alert,
      .error-alert {
        width: calc(100% - 40px);
        right: 20px;
      }
    }

    .compact-row {
      --bs-gutter-x: 0.5rem;
    }

    .custom-modal-width {
      max-width: 900px;
    }

    .modal .table-modal thead {
      background: #2563eb;
      color: #fff;
    }

    .modal .table-modal th,
    .modal .table-modal td {
      border: 2px solid #2563eb !important;
      vertical-align: middle;
    }

    .modal .table-modal {
      border-radius: 6px;
      overflow: hidden;
    }

    .table-primary th {
      font-weight: 500 !important;
      text-align: center;
    }

    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }

    input[type=number] {
      -moz-appearance: textfield;
    }

    .input-group-text {
      border: 2px solid #999;
      border-right: none;
    }

    .input-group .form-control {
      border: 2px solid #999;
      border-left: none;
      border-right: none;
    }

    .input-group .form-select {
      border: 2px solid #999;
      border-left: none;
    }

    .input-group .form-control:focus,
    .input-group .form-select:focus {
      border-color: #86b7fe;
      box-shadow: none;
    }

    .input-group .form-control:focus+.input-group-text,
    .input-group .form-select:focus {
      border-color: #86b7fe;
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="logo">
      <img class="jlq-logo" src="img/jlq-logo.png" alt="JLQ Logo" />
      <div class="logo-title">
        <h1>JLQ</h1>
        <span class="jlq-desc">Purchase Order</span>
      </div>
    </div>
    <div class="navigation-bar">
      <a class="nav-btn active" href="purchase-order.php"><i class="fas fa-file-invoice"></i> Purchase Order</a>
    </div>
  </div>
  <div class="content">
    <i class="fas fa-bars hamburger" id="hamburger"></i>
    <div class="card">
      <div class="d-flex justify-content-between mb-4">
        <h2><img src="img/gif/grocery.gif" alt="" style="width: 40px;"> Purchase Order</h2>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAccountModal">
          <i class="fa-solid fa-plus"></i> Add Purchase Order
        </button>
      </div>

      <div>
        <div class="row mb-3">
          <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
              <div class="d-flex gap-3 align-items-center">
                <div>
                  <label for="monthFilter" class="form-label">Month:</label>
                  <select class="form-select" id="monthFilter" style=" border: 2px solid #2563eb;">
                    <option value="" selected disabled>All Months</option>
                    <?php
                    $months = [];
                    foreach ($companies as $company) {
                      $date = new DateTime($company['date_created']);
                      $month = $date->format('F');
                      if (!in_array($month, $months)) {
                        $months[] = $month;
                        echo "<option value='" . $date->format('m') . "'>" . $month . "</option>";
                      }
                    }
                    ?>
                  </select>
                </div>

                <div>
                  <label for="yearFilter" class="form-label">Year:</label>
                  <select class="form-select" id="yearFilter" style=" border: 2px solid #2563eb;">
                    <option value="" selected disabled>All Years</option>
                    <?php
                    $years = [];
                    foreach ($companies as $company) {
                      $date = new DateTime($company['date_created']);
                      $year = $date->format('Y');
                      if (!in_array($year, $years)) {
                        $years[] = $year;
                        echo "<option value='" . $year . "'>" . $year . "</option>";
                      }
                    }
                    ?>
                  </select>
                </div>
              </div>

              <div class="d-flex gap-3 align-items-center">
                <div style="width: 400px;">
                  <label for="searchFilter" class="form-label">Search:</label>
                  <div class="input-group">
                    <span class="input-group-text bg-white" style="border-top-right-radius: 0; border-bottom-right-radius: 0; border: 2px solid #2563eb;">
                      <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search..." style="border-radius: 0; border: 2px solid #2563eb;">
                    <select class="form-select" id="searchType" style="max-width: 198px; border-top-left-radius: 0; border-bottom-left-radius: 0; border: 2px solid #2563eb;">
                      <option value="po_number">P.O#</option>
                      <option value="company_name">Company Name</option>
                      <option value="total">Total</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label class="form-label" style="text-align: center; width: auto;">Total Purchase Order</label>
                  <div class="bg-light p-2 rounded" style="height: 38px; display: flex; align-items: center; justify-content: center; min-width: 120px; border: 2px solid #2563eb;">
                    <span id="totalPO" class="fw-bold" style="font-size: 1.1rem; color: #2563eb; ">0</span>
                  </div>
                </div>

                <div>
                  <label class="form-label" style="text-align: center; width: 100%;">Total Amount</label>
                  <div class="bg-light p-2 rounded" style="height: 38px; display: flex; align-items: center; justify-content: center; min-width: 180px; border: 2px solid #2563eb;">
                    <span id="totalAmountDisplay" class="fw-bold" style="font-size: 1.1rem; color: #2563eb;">₱0.00</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible success-alert" role="alert">
          <?php echo htmlspecialchars($success_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible error-alert" role="alert">
          <?php echo htmlspecialchars($error_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Add Purchase Order Modal -->
      <div class="modal fade" id="addAccountModal" tabindex="-1" aria-labelledby="addAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl custom-modal-width">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="addAccountModalLabel"><img src="img/gif/add-basket.gif" alt="" style="width: 40px;"> Add Purchase Order</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form id="addAccountForm" method="POST" onsubmit="return handleFormSubmit(event)">
                <input type="hidden" name="form_token" value="<?php echo $_SESSION['form_token']; ?>">
                <div class="mb-2">
                  <div class="row compact-row">
                    <div class="col-md-3">
                      <label for="poNumber" class="form-label">P.O#</label>
                      <input type="text" class="form-control" id="poNumber" name="poNumber" value="<?php echo $next_po_number; ?>" readonly />
                    </div>
                    <div class="col-md-9">
                      <label for="companyName" class="form-label">Company Name</label>
                      <input type="text" class="form-control" id="companyName" name="companyName" />
                    </div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="row compact-row">
                    <div class="col-md-6">
                      <label for="streetAddress" class="form-label">Street Address</label>
                      <input type="text" class="form-control" id="streetAddress" name="streetAddress" />
                    </div>
                    <div class="col-md-3">
                      <label for="city" class="form-label">City</label>
                      <input type="text" class="form-control" id="city" name="city" />
                    </div>
                    <div class="col-md-3">
                      <label for="phoneNumber" class="form-label">Contact Number</label>
                      <input type="tel" class="form-control" id="phoneNumber" name="phoneNumber" />
                    </div>
                  </div>
                </div>

                <div style="border-bottom: 1px solid #ccc; width: 100%; margin-bottom: 20px;"></div>
                <div class="mb-2">
                  <table class="table table-striped table-bordered table-hover mb-0">
                    <thead class="table-primary text-center">
                      <tr>
                        <th>Requisitioner</th>
                        <th>Ship Via</th>
                        <th>F.O.B</th>
                        <th>Shipping Terms</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td><input type="text" class="form-control" name="requisitioner"></td>
                        <td><input type="text" class="form-control" name="ship_via"></td>
                        <td><input type="text" class="form-control" name="fob"></td>
                        <td><input type="text" class="form-control" name="shipping_terms" value="COD"></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="mb-2">
                  <table class="table table-striped table-bordered table-hover mb-0" id="itemsTable">
                    <thead class="table-primary text-center">
                      <tr>
                        <th style="width:15%">Item #</th>
                        <th style="width:35%">Description</th>
                        <th style="width:10%">QTY</th>
                        <th style="width:20%">Unit Price</th>
                        <th style="width:20%">Total</th>
                      </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                      <tr>
                        <td class="text-center align-middle">1</td>
                        <td><input type="text" class="form-control" name="description[]"></td>
                        <td><input type="number" inputmode="decimal" class="form-control text-center qty-input" name="qty[]" min="1"></td>
                        <td>
                          <div class="currency-input-wrapper position-relative">
                            <span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2"></span>
                            <input type="number" inputmode="decimal" class="form-control text-end unit-price-input ps-4" name="unit_price[]" min="0" step="0.01" style="padding-left:2em;" />
                          </div>
                        </td>
                        <td>
                          <div class="currency-input-wrapper position-relative">
                            <span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2"></span>
                            <input type="number" inputmode="decimal" class="form-control text-end total-input bg-light ps-4" name="total[]" min="0" step="0.01" readonly tabindex="-1" style="pointer-events:none; opacity:0.7; padding-left:2em;" />
                          </div>
                        </td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td colspan="3"></td>
                        <td class="fw-bold text-end">Gross:</td>
                        <td class="fw-bold text-end"><span class="currency-symbol-footer"></span><span id="grandTotal">0.00</span></td>
                      </tr>
                      <tr>
                        <td colspan="3"></td>
                        <td class="fw-bold text-end">Vatable:</td>
                        <td class="fw-bold text-end"><span class="currency-symbol-footer"></span><span id="taxInput">0.00</span></td>
                      </tr>
                      <tr>
                        <td colspan="3"></td>
                        <td class="fw-bold text-end">VAT:</td>
                        <td class="fw-bold text-end"><span class="currency-symbol-footer"></span><span id="shippingInput">0.00</span></td>
                      </tr>
                      <tr>
                        <td colspan="3"></td>
                        <td class="fw-bold text-end">Other:</td>
                        <td class="fw-bold text-end">
                          <div class="currency-input-wrapper position-relative">
                            <span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2"></span>
                            <input type="number" inputmode="decimal" class="form-control text-end ps-4 footer-input" id="otherInput" name="otherInput" min="0" step="0.01" value="0" style="padding-left:2em; display:inline-block;" maxlength="10" oninput="limitLength(this)" />
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td colspan="3"></td>
                        <td class="fw-bold text-end">Total:</td>
                        <td class="fw-bold text-end"><span class="currency-symbol-footer"></span><span id="totalAmount">0.00</span></td>
                      </tr>
                    </tfoot>
                  </table>
                  <div class="d-flex justify-content-end gap-2 mt-2 align-items-center">
                    <div class="me-auto d-flex align-items-center">
                      <label for="currencySelect" class="me-2 mb-0 fw-bold">Currency:</label>
                      <select id="currencySelect" class="form-select form-select-sm w-auto">
                        <option value="PHP">₱ Peso</option>
                        <option value="USD">$ USD</option>
                      </select>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="addItemBtn"><i class="fas fa-plus"></i></button>
                    <button type="button" class="btn btn-danger btn-sm" id="removeItemBtn" disabled><i class="fas fa-trash"></i></button>
                    <button type="button" class="btn btn-secondary btn-sm" id="resetItemBtn"><i class="fas fa-rotate-right"></i></button>
                  </div>
                </div>
                <input type="hidden" name="grandTotal" id="hiddenGrandTotal" value="0">
                <input type="hidden" name="totalAmount" id="hiddenTotalAmount" value="0">
                <input type="hidden" name="currency" id="hiddenCurrency" value="PHP">
                <input type="hidden" name="edit_id" id="edit_id" value="">
                <input type="hidden" name="savePurchaseOrder" value="1">
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  <button type="submit" class="btn btn-success">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th scope="col">P.O#</th>
              <th scope="col">Company Name</th>
              <th scope="col">Total</th>
              <th scope="col">Date Purchase</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($companies)): ?>
              <tr>
                <td colspan="5" class="no-data">No Purchase Orders Available</td>
              </tr>
            <?php else: ?>
              <?php foreach ($companies as $company): ?>
                <tr data-id="<?php echo $company['id']; ?>" <?php if (!empty($company['is_cancelled']) && $company['is_cancelled']) echo ' class="cancelled-row"'; ?>>
                  <td><?php echo htmlspecialchars($company['po_number']); ?></td>
                  <td><?php echo htmlspecialchars($company['company_name']); ?></td>
                  <td>
                    <?php
                    $currency_symbol = ($company['currency'] === 'USD') ? '$' : '₱';
                    echo $currency_symbol . number_format($company['total'], 2);
                    ?>
                  </td>
                  <td><?php
                      $date = new DateTime($company['date_created']);
                      echo $date->format('d M, Y - h:i A');
                      ?></td>
                  <td>
                    <button class="btn btn-primary btn-sm" onclick='editPurchaseOrder(<?php echo json_encode($company, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>)'>
                      <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="deletePurchaseOrder(<?php echo $company['id']; ?>)">
                      <i class="fas fa-trash"></i>
                    </button>
                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#viewPurchaseOrderModal">
                      <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-warning btn-sm" title="Cancel">
                      <i class="fas fa-ban"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- View Purchase Order Modal -->
  <div class="modal fade" id="viewPurchaseOrderModal" tabindex="-1" aria-labelledby="viewPurchaseOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="viewPurchaseOrderModalLabel"><img src="img/gif/zoom-in.gif" alt="" style="width: 40px;"> View Purchase Order</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex align-items-center gap-2" style="color: #02528b; margin-bottom: 10px;">
            <img src="img/jlq-logo.png" alt="" style="width: 75px;">
            <span style="font-size: 1.8rem; font-weight: bold;" id="companyNameDisplay">JLQ Holdings Corporation</span>
            <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="toggleCompanyBtn" title="Toggle Company Name">
              <i class="fas fa-sync-alt"></i>
            </button>
          </div>

          <div class="d-flex" style="padding: 10px; gap: 20px;">
            <div style="flex: 1;">
              <h5 style="font-weight: bold; margin-bottom: 0 !important;" id="companyNameHeader">JLQ Holdings Corporation</h5>
              <div class="d-flex" style="font-size: 0.9rem; font-weight: 500; color: #222; flex-direction: column;">
                <span>
                  3rd Flr. Annex Bldg. King`s Court, 2129 Chino Roces Ave. Pio Del Pillar Makati City</span>
                <span>jlqservices2016@gmail.com</span>
              </div>
            </div>
            <div style="flex: 1;">
              <h5 style="font-weight: bold; margin-bottom: 0 !important;">Purchase Order</h5>
              <div class="d-flex" style="font-size: 0.9rem; font-weight: 500; color: #222; flex-direction: column;">
                <span><b>Purchase Order No. : </b><span id="viewPoNumber">N/A</span></span>
                <span><b>Purchase Order Date : </b><span id="viewPoDate">N/A</span></span>
              </div>
            </div>
          </div>

          <table class="table table-bordered">
            <thead style="background-color: #2563eb; color: white;">
              <tr>
                <th style="width: 50%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Vendor</th>
                <th style="width: 50%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Ship to</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td style="width: 50%; vertical-align: top;">
                  <div class="mb-1"><b>Company Name:</b> <span id="viewVendorCompany">N/A</span></div>
                  <div class="mb-1"><b>Street Address:</b> <span id="viewVendorAddress">N/A</span></div>
                  <div class="mb-1"><b>City:</b> <span id="viewVendorCity">N/A</span></div>
                  <div class="mb-1"><b>Contact No:</b> <span id="viewVendorContact">N/A</span></div>
                </td>
                <td style="width: 50%; vertical-align: top;">
                  <div class="mb-1"><b>Name:</b> Jose Ma. L. Quiaoit</div>
                  <div class="mb-1"><b>Company Name:</b> JLQ Holdings Corporation</div>
                  <div class="mb-1"><b>Office Address:</b> 3rd Flr. Annex Bldg. King`s Court, 2129 Chino Roces Ave. Pio Del Pillar Makati City</div>
                  <div class="mb-1"><b>Phone No:</b></div>
                </td>
              </tr>
            </tbody>
          </table>

          <table class="table table-bordered">
            <thead style="background-color: #2563eb; color: white;">
              <tr>
                <th style="width: 25%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Requesitioner</th>
                <th style="width: 25%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Ship VIA</th>
                <th style="width: 25%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">F.O.B</th>
                <th style="width: 25%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Shipping Terms</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td style="width: 25%;">
                  <span id="viewRequisitioner">N/A</span>
                </td>
                <td style="width: 25%;">
                  <span id="viewShipVia">N/A</span>
                </td>
                <td style="width: 25%;">
                  <span id="viewFob">N/A</span>
                </td>
                <td style="width: 25%;">
                  <span id="viewShippingTerms">N/A</span>
                </td>
              </tr>
            </tbody>
          </table>

          <table class="table table-bordered">
            <thead style="background-color: #2563eb; color: white;">
              <tr>
                <th style="width: 15%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Item #</th>
                <th style="width: 35%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Description</th>
                <th style="width: 10%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">QTY</th>
                <th style="width: 20%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Unit Price</th>
                <th style="width: 20%; vertical-align: top; background-color: #54b5e6; color: white; text-transform: uppercase; font-size: 0.8rem;">Total</th>
              </tr>
            </thead>
            <tbody id="viewItemsTableBody">
              <!-- Items will be populated here -->
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3"></td>
                <td class="fw-bold text-end">Gross:</td>
                <td class="fw-bold text-end"><span id="viewSubtotal">0.00</span></td>
              </tr>
              <tr>
                <td colspan="3"></td>
                <td class="fw-bold text-end">Vatable:</td>
                <td class="fw-bold text-end"><span id="viewTax">0.00</span></td>
              </tr>
              <tr>
                <td colspan="3"></td>
                <td class="fw-bold text-end">VAT:</td>
                <td class="fw-bold text-end"><span id="viewShipping">0.00</span></td>
              </tr>
              <tr>
                <td colspan="3"></td>
                <td class="fw-bold text-end">Other:</td>
                <td class="fw-bold text-end"><span id="viewOther">0.00</span></td>
              </tr>
              <tr>
                <td colspan="3"></td>
                <td class="fw-bold text-end">Total:</td>
                <td class="fw-bold text-end"><span id="viewTotal">0.00</span></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="printPurchaseOrder()">Print</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content" style="height: auto !important;">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteConfirmationModalLabel"><img src="img/gif/trash-bin.gif" alt="" style="width: 40px;"> Confirm Delete</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          Are you sure you want to delete this purchase order?
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Cancel Confirmation Modal -->
  <div class="modal fade" id="cancelConfirmationModal" tabindex="-1" aria-labelledby="cancelConfirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content" style="height: auto !important;">
        <div class="modal-header">
          <h5 class="modal-title" id="cancelConfirmationModalLabel"><img src="img/gif/warning.gif" alt="" style="width: 40px;"> Confirm Action</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="cancelConfirmationMessage">
          <!-- Message will be set dynamically -->
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
          <button type="button" class="btn btn-warning" id="confirmCancelBtn">Yes</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq"
    crossorigin="anonymous"></script>
  <script>
    // Move editPurchaseOrder and deletePurchaseOrder functions outside of DOMContentLoaded
    function editPurchaseOrder(company) {
      // Clear any previous error messages
      const errorAlert = document.querySelector('.error-alert');
      if (errorAlert) {
        errorAlert.remove();
      }

      // Show the modal
      var modal = new bootstrap.Modal(document.getElementById('addAccountModal'));
      const modalLabel = document.getElementById('addAccountModalLabel');
      if (modalLabel) {
        modalLabel.innerHTML = '<img src="img/gif/edit.gif" alt="" style="width: 40px;"> Edit Purchase Order';
      }

      // Helper function to safely set value
      function setElementValue(id, value) {
        const element = document.getElementById(id);
        if (element) {
          element.value = value || '';
        }
      }

      // Fill in the main form fields
      setElementValue('poNumber', company.po_number);
      setElementValue('companyName', company.company_name);
      setElementValue('streetAddress', company.street_address);
      setElementValue('city', company.city);
      setElementValue('phoneNumber', company.contact_number);
      setElementValue('edit_id', company.id);

      // Set currency and update symbols
      const currencySelect = document.getElementById('currencySelect');
      const hiddenCurrency = document.getElementById('hiddenCurrency');
      if (currencySelect) {
        currencySelect.value = company.currency || 'PHP';
        const event = new Event('change');
        currencySelect.dispatchEvent(event);
      }
      if (hiddenCurrency) {
        hiddenCurrency.value = company.currency || 'PHP';
      }

      // Fill in shipping details
      const shippingFields = ['requisitioner', 'ship_via', 'fob', 'shipping_terms'];
      shippingFields.forEach(field => {
        const elements = document.getElementsByName(field);
        if (elements && elements[0]) {
          elements[0].value = company[field] || '';
        }
      });

      // Set amounts
      setElementValue('taxInput', parseFloat(company.tax || 0).toFixed(2));
      setElementValue('shippingInput', parseFloat(company.shipping || 0).toFixed(2));
      setElementValue('otherInput', parseFloat(company.other || 0).toFixed(2));

      // Set totals
      const grandTotal = document.getElementById('grandTotal');
      const hiddenGrandTotal = document.getElementById('hiddenGrandTotal');
      const totalAmount = document.getElementById('totalAmount');
      const hiddenTotalAmount = document.getElementById('hiddenTotalAmount');

      if (grandTotal) {
        grandTotal.textContent = parseFloat(company.subtotal || 0).toFixed(2);
      }
      if (hiddenGrandTotal) {
        hiddenGrandTotal.value = parseFloat(company.subtotal || 0).toFixed(2);
      }
      if (totalAmount) {
        totalAmount.textContent = parseFloat(company.total || 0).toFixed(2);
      }
      if (hiddenTotalAmount) {
        hiddenTotalAmount.value = parseFloat(company.total || 0).toFixed(2);
      }

      // Load items from database
      fetch('?get_items=' + company.id)
        .then(res => {
          if (!res.ok) {
            return res.json().then(err => {
              throw new Error(err.error || 'Failed to load items');
            });
          }
          return res.json();
        })
        .then(items => {
          const tbody = document.getElementById('itemsTableBody');
          if (!tbody) {
            console.error('Items table body not found');
            return;
          }

          tbody.innerHTML = '';

          if (!items || items.length === 0) {
            tbody.innerHTML = `<tr>
                        <td class="text-center align-middle">1</td>
                        <td><input type="text" class="form-control" name="description[]"></td>
                        <td><input type="number" inputmode="decimal" class="form-control text-center qty-input" name="qty[]" min="1"></td>
                        <td><div class="currency-input-wrapper position-relative"><span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2">${currencySelect.value === 'USD' ? '$' : '₱'}</span><input type="number" inputmode="decimal" class="form-control text-end unit-price-input ps-4" name="unit_price[]" min="0" step="0.01" style="padding-left:2em;"></div></td>
                        <td><div class="currency-input-wrapper position-relative"><span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2">${currencySelect.value === 'USD' ? '$' : '₱'}</span><input type="number" inputmode="decimal" class="form-control text-end total-input bg-light ps-4" name="total[]" readonly tabindex="-1" style="pointer-events:none; opacity:0.7; padding-left:2em;"></div></td>
                    </tr>`;
          } else {
            items.forEach((item, i) => {
              tbody.innerHTML += `<tr>
                            <td class="text-center align-middle">${i + 1}</td>
                            <td><input type="text" class="form-control" name="description[]" value="${item.description.replace(/"/g, '&quot;')}"></td>
                            <td><input type="number" inputmode="decimal" class="form-control text-center qty-input" name="qty[]" value="${item.qty}" min="1"></td>
                            <td><div class="currency-input-wrapper position-relative"><span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2">${currencySelect.value === 'USD' ? '$' : '₱'}</span><input type="number" inputmode="decimal" class="form-control text-end unit-price-input ps-4" name="unit_price[]" value="${item.unit_price}" min="0" step="0.01" style="padding-left:2em;"></div></td>
                            <td><div class="currency-input-wrapper position-relative"><span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2">${currencySelect.value === 'USD' ? '$' : '₱'}</span><input type="number" inputmode="decimal" class="form-control text-end total-input bg-light ps-4" name="total[]" value="${item.total}" readonly tabindex="-1" style="pointer-events:none; opacity:0.7; padding-left:2em;"></div></td>
                        </tr>`;
            });
          }

          // Update buttons state based on number of items
          const removeItemBtn = document.getElementById('removeItemBtn');
          if (removeItemBtn) {
            removeItemBtn.disabled = items.length <= 1;
          }

          // Attach event listeners to all rows
          function calculateRowTotal(row) {
            const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
            const unitPrice = parseFloat(row.querySelector('.unit-price-input')?.value) || 0;
            const total = qty * unitPrice;
            const totalInput = row.querySelector('.total-input');
            if (totalInput) totalInput.value = total ? total.toFixed(2) : '';
          }

          function calculateGrandTotal() {
            let subtotal = 0;
            document.querySelectorAll('.total-input').forEach(input => {
              subtotal += parseFloat(input.value) || 0;
            });

            const other = parseFloat(document.getElementById('otherInput')?.value) || 0;

            // Add other amount to subtotal for gross
            const grossTotal = subtotal + other;

            // Calculate VAT and Vatable based on the gross total
            const vat = (grossTotal * 12) / 112;
            const vatable = grossTotal - vat;

            // Update the display values
            document.getElementById('grandTotal').textContent = grossTotal.toFixed(2);
            document.getElementById('hiddenGrandTotal').value = grossTotal.toFixed(2);

            // Update VAT and Vatable fields
            document.getElementById('taxInput').textContent = vatable.toFixed(2);
            document.getElementById('shippingInput').textContent = vat.toFixed(2);

            // Total remains the same (gross total)
            document.getElementById('totalAmount').textContent = grossTotal.toFixed(2);
            document.getElementById('hiddenTotalAmount').value = grossTotal.toFixed(2);
          }

          // Attach event listeners to all rows
          Array.from(tbody.children).forEach(row => {
            const qtyInput = row.querySelector('.qty-input');
            const unitPriceInput = row.querySelector('.unit-price-input');

            if (qtyInput) {
              qtyInput.addEventListener('input', () => {
                calculateRowTotal(row);
                calculateGrandTotal();
              });
            }

            if (unitPriceInput) {
              unitPriceInput.addEventListener('input', () => {
                calculateRowTotal(row);
                calculateGrandTotal();
              });
            }
          });

          // Initial calculations
          Array.from(tbody.children).forEach(row => calculateRowTotal(row));
          calculateGrandTotal();

          // Update currency symbols
          updateCurrencySymbolInputs();
          updateFooterCurrencySymbols();

          // Attach strict numeric input handlers
          attachStrictNumericToAll();

          // Update buttons state
          updateButtons();
        })
        .catch(error => {
          console.error('Error loading items:', error);
        });

      modal.show();
    }

    function deletePurchaseOrder(id) {
      const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

      // Reset the button
      confirmDeleteBtn.disabled = false;
      confirmDeleteBtn.innerHTML = 'Delete';

      // Create a new button to replace the old one
      const newConfirmDeleteBtn = confirmDeleteBtn.cloneNode(true);
      confirmDeleteBtn.parentNode.replaceChild(newConfirmDeleteBtn, confirmDeleteBtn);

      newConfirmDeleteBtn.addEventListener('click', function() {
        // Disable button immediately after click
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';

        fetch(window.location.href, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'deletePurchaseOrder=1&id=' + id
          })
          .then(response => response.text())
          .then(() => {
            deleteModal.hide();
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success alert-dismissible success-alert';
            successAlert.innerHTML = `
                    Purchase order deleted successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
            document.body.appendChild(successAlert);

            setTimeout(() => {
              window.location.reload();
            }, 500);
          })
          .catch(error => {
            console.error('Error:', error);
            const errorAlert = document.createElement('div');
            errorAlert.className = 'alert alert-danger alert-dismissible error-alert';
            errorAlert.innerHTML = `
                    An error occurred while deleting the purchase order.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
            document.body.appendChild(errorAlert);

            // Re-enable button on error
            newConfirmDeleteBtn.disabled = false;
            newConfirmDeleteBtn.innerHTML = 'Delete';

            setTimeout(() => {
              errorAlert.style.transition = 'transform 0.5s ease-in-out';
              errorAlert.style.transform = 'translateX(100%)';
              setTimeout(() => {
                errorAlert.remove();
              }, 500);
            }, 3000);
          });
      });

      deleteModal.show();
    }

    document.addEventListener('DOMContentLoaded', function() {
      const maxItems = 18; // Changed from 20 to 18
      const minItems = 1;
      const itemsTableBody = document.getElementById('itemsTableBody');
      const addItemBtn = document.getElementById('addItemBtn');
      const removeItemBtn = document.getElementById('removeItemBtn');
      const currencySelect = document.getElementById('currencySelect');

      function updateButtons() {
        const rowCount = itemsTableBody.children.length;
        addItemBtn.disabled = rowCount >= maxItems;
        removeItemBtn.disabled = rowCount <= minItems;
      }

      function updateItemNumbers() {
        Array.from(itemsTableBody.children).forEach((row, idx) => {
          row.querySelector('td').textContent = idx + 1;
        });
      }

      addItemBtn.addEventListener('click', function() {
        const rowCount = itemsTableBody.children.length;
        if (rowCount < maxItems) {
          const newRow = document.createElement('tr');
          newRow.innerHTML = `
                    <td class="text-center align-middle">${rowCount + 1}</td>
                    <td><input type="text" class="form-control" name="description[]"></td>
                    <td><input type="number" inputmode="decimal" class="form-control text-center qty-input" name="qty[]" min="1"></td>
                    <td>
                        <div class="currency-input-wrapper position-relative">
                            <span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2"></span>
                            <input type="number" inputmode="decimal" class="form-control text-end unit-price-input ps-4" name="unit_price[]" min="0" step="0.01" style="padding-left:2em;" />
                        </div>
                    </td>
                    <td>
                        <div class="currency-input-wrapper position-relative">
                            <span class="currency-symbol-input position-absolute start-0 top-50 translate-middle-y ps-2"></span>
                            <input type="number" inputmode="decimal" class="form-control text-end total-input bg-light ps-4" name="total[]" min="0" step="0.01" readonly tabindex="-1" style="pointer-events:none; opacity:0.7; padding-left:2em;" />
                        </div>
                    </td>
                `;
          itemsTableBody.appendChild(newRow);
          const modalContent = document.querySelector('#addAccountModal .modal-content');
          if (modalContent) {
            modalContent.scrollTop = modalContent.scrollHeight;
          }
          updateCurrencySymbolInputs();
          updateButtons();
        }
      });

      removeItemBtn.addEventListener('click', function() {
        const rowCount = itemsTableBody.children.length;
        if (rowCount > minItems) {
          itemsTableBody.removeChild(itemsTableBody.lastElementChild);
          updateItemNumbers();
          updateButtons();
          calculateGrandTotal();
        }
      });

      updateButtons();

      const resetItemBtn = document.getElementById('resetItemBtn');
      resetItemBtn.addEventListener('click', function() {
        while (itemsTableBody.children.length > 1) {
          itemsTableBody.removeChild(itemsTableBody.lastElementChild);
        }
        const firstRow = itemsTableBody.children[0];
        if (firstRow) {
          firstRow.querySelectorAll('input').forEach(input => input.value = '');
        }
        updateItemNumbers();
        updateButtons();
        calculateGrandTotal();
      });

      function calculateRowTotal(row) {
        const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price-input')?.value) || 0;
        const total = qty * unitPrice;
        const totalInput = row.querySelector('.total-input');
        if (totalInput) totalInput.value = total ? total.toFixed(2) : '';
      }

      function calculateGrandTotal() {
        let subtotal = 0;
        document.querySelectorAll('.total-input').forEach(input => {
          subtotal += parseFloat(input.value) || 0;
        });

        const other = parseFloat(document.getElementById('otherInput')?.value) || 0;

        // Add other amount to subtotal for gross
        const grossTotal = subtotal + other;

        // Calculate VAT and Vatable based on the gross total
        const vat = (grossTotal * 12) / 112;
        const vatable = grossTotal - vat;

        // Update the display values
        document.getElementById('grandTotal').textContent = grossTotal.toFixed(2);
        document.getElementById('hiddenGrandTotal').value = grossTotal.toFixed(2);

        // Update VAT and Vatable fields
        document.getElementById('taxInput').textContent = vatable.toFixed(2);
        document.getElementById('shippingInput').textContent = vat.toFixed(2);

        // Total remains the same (gross total)
        document.getElementById('totalAmount').textContent = grossTotal.toFixed(2);
        document.getElementById('hiddenTotalAmount').value = grossTotal.toFixed(2);
      }

      function attachInputListeners(row) {
        const qtyInput = row.querySelector('.qty-input');
        const unitPriceInput = row.querySelector('.unit-price-input');
        [qtyInput, unitPriceInput].forEach(input => {
          if (input) {
            input.addEventListener('input', function() {
              calculateRowTotal(row);
              calculateGrandTotal();
            });
          }
        });
      }

      function attachListenersToAllRows() {
        Array.from(itemsTableBody.children).forEach(row => {
          attachInputListeners(row);
          attachCurrencyInputEvents(row);
        });
      }

      attachListenersToAllRows();

      addItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachListenersToAllRows();
        }, 0);
      });

      removeItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachListenersToAllRows();
          calculateGrandTotal();
        }, 0);
      });

      resetItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachListenersToAllRows();
          calculateRowTotal(itemsTableBody.children[0]);
          calculateGrandTotal();
        }, 0);
      });

      function updateCurrencySymbolInputs() {
        const symbol = currencySelect.value === 'USD' ? '$' : '₱';
        document.querySelectorAll('.unit-price-inputunit-price-input').forEach(input => {
          const span = input.parentElement.querySelector('.currency-symbol-input');
          span.textContent = symbol;
          span.style.display = '';
        });
        document.querySelectorAll('.total-input').forEach(input => {
          const span = input.parentElement.querySelector('.currency-symbol-input');
          span.textContent = symbol;
          span.style.display = '';
        });
      }

      function attachCurrencyInputEvents(row) {
        const unitPriceInput = row.querySelector('.unit-price-input');
        const totalInput = row.querySelector('.total-input');
        if (unitPriceInput) {
          unitPriceInput.addEventListener('input', updateCurrencySymbolInputs);
        }
        if (totalInput) {
          totalInput.addEventListener('input', updateCurrencySymbolInputs);
        }
      }

      function updateFooterCurrencySymbols() {
        const symbol = currencySelect.value === 'USD' ? '$' : '₱';
        document.querySelectorAll('.footer-input').forEach(input => {
          const span = input.parentElement.querySelector('.currency-symbol-input');
          if (span) span.textContent = symbol;
        });
        document.querySelectorAll('.currency-symbol-footer').forEach(span => {
          span.textContent = symbol;
        });
      }

      currencySelect.addEventListener('change', function() {
        updateCurrencySymbolInputs();
        updateFooterCurrencySymbols();
        document.getElementById('hiddenCurrency').value = this.value;
      });

      updateCurrencySymbolInputs();
      updateFooterCurrencySymbols();

      document.addEventListener('input', function(e) {
        if (e.target.classList.contains('unit-price-input') || e.target.classList.contains('total-input')) {
          updateCurrencySymbolInputs();
        }
      });

      function enforceStrictNumericInput(input) {
        input.addEventListener('keydown', function(e) {
          if ([46, 8, 9, 27, 13, 110, 190].includes(e.keyCode) ||
            (e.keyCode >= 35 && e.keyCode <= 40)) {
            return;
          }
          if ((e.ctrlKey || e.metaKey) && [65, 67, 86, 88].includes(e.keyCode)) {
            return;
          }
          if ((e.keyCode >= 48 && e.keyCode <= 57 && !e.shiftKey) ||
            (e.keyCode >= 96 && e.keyCode <= 105)) {
            return;
          }
          if ((e.key === '.' || e.key === ',') && !input.value.includes('.')) {
            return;
          }
          e.preventDefault();
        });
      }

      function attachStrictNumericToAll() {
        document.querySelectorAll('.qty-input, .unit-price-input, .total-input, .footer-input').forEach(input => {
          enforceStrictNumericInput(input);
        });
      }

      attachStrictNumericToAll();
      addItemBtn.addEventListener('click', function() {
        setTimeout(attachStrictNumericToAll, 0);
      });
      removeItemBtn.addEventListener('click', function() {
        setTimeout(attachStrictNumericToAll, 0);
      });
      resetItemBtn.addEventListener('click', function() {
        setTimeout(attachStrictNumericToAll, 0);
      });

      ['taxInput', 'shippingInput', 'otherInput'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.addEventListener('input', function() {
            calculateGrandTotal();
          });
        }
      });

      function limitLength(element) {
        if (element.value.length > 10) {
          element.value = element.value.slice(0, 10);
        }
      }

      function handleFormSubmit(event) {
        event.preventDefault();

        // Get the form and save button
        const form = event.target;
        const saveButton = form.querySelector('button[type="submit"]');

        // Disable the button and show loading state
        saveButton.disabled = true;
        saveButton.innerHTML = `
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        <span class="ms-2">Saving...</span>
    `;

        // Collect form data
        const formData = new FormData(form);

        // Add timestamp to prevent caching
        formData.append('timestamp', new Date().getTime());

        // Perform the fetch request
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            signal: AbortSignal.timeout(30000) // 30-second timeout
          })
          .then(response => {
            if (!response.ok) {
              throw new Error('Network response was not ok');
            }
            return response.text();
          })
          .then(html => {
            // Hide the modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('addAccountModal'));
            modal.hide();

            // Create success alert
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success alert-dismissible success-alert';
            successAlert.innerHTML = `
            Purchase order ${formData.get('edit_id') ? 'updated' : 'saved'} successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
            document.body.appendChild(successAlert);

            // Auto-hide success alert after 3 seconds
            setTimeout(() => {
              successAlert.style.transition = 'transform 0.5s ease-in-out';
              successAlert.style.transform = 'translateX(100%)';
              setTimeout(() => {
                successAlert.remove();
              }, 500);
            }, 3000);

            // Reload the page to reflect changes
            setTimeout(() => {
              window.location.reload();
            }, 500);
          })
          .catch(error => {
            console.error('Error:', error);

            // Re-enable the button and restore original text
            saveButton.disabled = false;
            saveButton.innerHTML = 'Save';

            // Create error alert
            const errorAlert = document.createElement('div');
            errorAlert.className = 'alert alert-danger alert-dismissible error-alert';
            errorAlert.innerHTML = `
            An error occurred while saving the purchase order.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
            document.body.appendChild(errorAlert);

            // Auto-hide error alert after 3 seconds
            setTimeout(() => {
              errorAlert.style.transition = 'transform 0.5s ease-in-out';
              errorAlert.style.transform = 'translateX(100%)';
              setTimeout(() => {
                errorAlert.remove();
              }, 500);
            }, 3000);
          });

        return false;
      }

      function validateRequiredFields() {
        const saveButton = document.querySelector('#addAccountForm button[type="submit"]');
        const rows = document.querySelectorAll('#itemsTableBody tr');
        let isValid = true;

        if (rows.length === 0) {
          saveButton.disabled = true;
          return;
        }

        rows.forEach(row => {
          const description = row.querySelector('input[name="description[]"]')?.value.trim() || '';
          const qty = row.querySelector('input[name="qty[]"]')?.value.trim() || '';
          const unitPrice = row.querySelector('input[name="unit_price[]"]')?.value.trim() || '';

          if (!description || !qty || !unitPrice) {
            isValid = false;
          }
        });

        saveButton.disabled = !isValid;
      }

      function attachRequiredFieldListeners() {
        const rows = document.querySelectorAll('#itemsTableBody tr');
        rows.forEach(row => {
          const inputs = row.querySelectorAll('input[name="description[]"], input[name="qty[]"], input[name="unit_price[]"]');
          inputs.forEach(input => {
            input.removeEventListener('input', validateRequiredFields);
            input.addEventListener('input', validateRequiredFields);
          });
        });
      }

      addItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachRequiredFieldListeners();
          validateRequiredFields();
        }, 100);
      });

      removeItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachRequiredFieldListeners();
          validateRequiredFields();
        }, 100);
      });

      resetItemBtn.addEventListener('click', function() {
        setTimeout(() => {
          attachRequiredFieldListeners();
          validateRequiredFields();
        }, 100);
      });

      setTimeout(() => {
        attachRequiredFieldListeners();
        validateRequiredFields();
      }, 100);

      document.getElementById('addAccountModal').addEventListener('shown.bs.modal', function() {
        setTimeout(() => {
          attachRequiredFieldListeners();
          validateRequiredFields();
        }, 100);
      });

      document.getElementById('itemsTableBody').addEventListener('input', function(e) {
        if (e.target.matches('input[name="description[]"], input[name="qty[]"], input[name="unit_price[]"]')) {
          validateRequiredFields();
        }
      });

      document.querySelector('#addAccountModal .btn-close').addEventListener('click', function() {
        window.location.reload();
      });

      document.querySelector('#addAccountModal .modal-footer .btn-secondary').addEventListener('click', function() {
        window.location.reload();
      });

      document.getElementById('hamburger').addEventListener('click', function() {
        document.querySelector('.header').classList.toggle('active');
      });

      // Filter functionality for Month and Year
      const monthFilter = document.getElementById('monthFilter');
      const yearFilter = document.getElementById('yearFilter');
      const tableRows = document.querySelectorAll('.table-responsive tbody tr');

      function filterTable() {
        const selectedMonth = monthFilter.value;
        const selectedYear = yearFilter.value;

        tableRows.forEach(row => {
          const dateCell = row.querySelector('td:nth-child(4)');
          if (!dateCell) return;

          const dateText = dateCell.textContent.trim();
          const [datePart] = dateText.split(' - ');
          const [day, month, year] = datePart.split(' ');
          const monthIndex = new Date(`${month} 1, 2000`).getMonth() + 1;
          const rowYear = parseInt(year.replace(',', ''));

          const monthMatch = !selectedMonth || monthIndex === parseInt(selectedMonth);
          const yearMatch = !selectedYear || rowYear === parseInt(selectedYear);

          row.style.display = monthMatch && yearMatch ? '' : 'none';
        });

        const visibleRows = Array.from(tableRows).filter(row => row.style.display !== 'none');
        const noDataRow = document.querySelector('.no-data');

        if (visibleRows.length === 0) {
          if (!noDataRow) {
            const tbody = document.querySelector('.table-responsive tbody');
            tbody.innerHTML = `
                        <tr>
                            <td colspan="5" class="no-data">No Purchase Orders Available for Selected Filter</td>
                        </tr>
                    `;
          }
        } else if (noDataRow) {
          noDataRow.closest('tr').remove();
        }

        updateTotalPO();
        updateTotalAmount();
      }

      monthFilter.addEventListener('change', filterTable);
      yearFilter.addEventListener('change', filterTable);

      // Auto-hide alerts after 3 seconds with slide out
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        setTimeout(() => {
          alert.style.transition = 'transform 0.5s ease-in-out';
          alert.style.transform = 'translateX(100%)';
          setTimeout(() => {
            alert.remove();
          }, 500);
        }, 3000);
      });

      // Search functionality
      const searchInput = document.getElementById('searchInput');
      const searchType = document.getElementById('searchType');
      let currentSearchValue = '';
      let currentSearchField = 'po_number';
      let originalTableContent = null;

      function updateTotalPO() {
        const tableRows = document.querySelectorAll('.table-responsive tbody tr');
        const visibleRows = Array.from(tableRows).filter(row =>
          !row.querySelector('.no-data') && row.style.display !== 'none'
        );
        document.getElementById('totalPO').textContent = visibleRows.length;
      }

      // Updated updateTotalAmount to handle multiple currencies
      function updateTotalAmount() {
        const tableRows = document.querySelectorAll('.table-responsive tbody tr');
        let phpTotal = 0;
        let usdTotal = 0;

        // Check if there are any visible rows
        const hasVisibleRows = Array.from(tableRows).some(row =>
          !row.querySelector('.no-data') && row.style.display !== 'none'
        );

        if (!hasVisibleRows) {
          document.getElementById('totalAmountDisplay').textContent = '₱0.00';
          return;
        }

        tableRows.forEach(row => {
          // SKIP cancelled rows!
          if (
            !row.querySelector('.no-data') &&
            row.style.display !== 'none' &&
            !row.classList.contains('cancelled-row')
          ) {
            const amountCell = row.querySelector('td:nth-child(3)');
            if (amountCell) {
              const amountText = amountCell.textContent.trim();
              const amount = parseFloat(amountText.replace(/[₱$,]/g, '')) || 0;
              if (amountText.startsWith('$')) {
                usdTotal += amount;
              } else {
                phpTotal += amount;
              }
            }
          }
        });

        // Format totals
        const phpFormatted = new Intl.NumberFormat('en-US', {
          style: 'currency',
          currency: 'PHP',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(phpTotal);

        const usdFormatted = new Intl.NumberFormat('en-US', {
          style: 'currency',
          currency: 'USD',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(usdTotal);

        // Display both currencies if they exist, otherwise show the one that exists
        let displayText = '';
        if (phpTotal > 0 && usdTotal > 0) {
          displayText = `${phpFormatted} - ${usdFormatted}`;
        } else if (phpTotal > 0) {
          displayText = phpFormatted;
        } else if (usdTotal > 0) {
          displayText = usdFormatted;
        } else {
          displayText = '₱0.00';
        }

        document.getElementById('totalAmountDisplay').textContent = displayText;
      }

      function performSearch() {
        currentSearchValue = searchInput.value.toLowerCase();
        currentSearchField = searchType.value;
        const tbody = document.querySelector('.table-responsive tbody');

        if (!originalTableContent && !tbody.querySelector('.no-data')) {
          originalTableContent = tbody.innerHTML;
        }

        if (!currentSearchValue) {
          if (originalTableContent) {
            tbody.innerHTML = originalTableContent;
            originalTableContent = null;
          }
          updateTotalPO();
          updateTotalAmount();
          return;
        }

        if (originalTableContent) {
          tbody.innerHTML = originalTableContent;
        }

        const tableRows = document.querySelectorAll('.table-responsive tbody tr');
        let hasVisibleRows = false;

        tableRows.forEach(row => {
          if (row.querySelector('.no-data')) return;

          let cellValue = '';
          switch (currentSearchField) {
            case 'po_number':
              cellValue = row.querySelector('td:nth-child(1)')?.textContent.toLowerCase() || '';
              break;
            case 'company_name':
              cellValue = row.querySelector('td:nth-child(2)')?.textContent.toLowerCase() || '';
              break;
            case 'total':
              cellValue = row.querySelector('td:nth-child(3)')?.textContent.toLowerCase() || '';
              break;
          }

          const matchesSearch = cellValue.includes(currentSearchValue);
          const matchesFilter = row.style.display !== 'none';

          if (matchesSearch && matchesFilter) {
            row.style.display = '';
            hasVisibleRows = true;
          } else {
            row.style.display = 'none';
          }
        });

        if (!hasVisibleRows) {
          tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="no-data">No Purchase Orders Available for Selected Filter</td>
                    </tr>
                `;
        }

        updateTotalPO();
        updateTotalAmount();
      }

      function reinitializeSearch() {
        const tbody = document.querySelector('.table-responsive tbody');

        originalTableContent = null;

        if (currentSearchValue) {
          performSearch();
        } else if (originalTableContent) {
          tbody.innerHTML = originalTableContent;
          updateTotalPO();
          updateTotalAmount();
        }
      }

      searchInput.addEventListener('input', function() {
        performSearch();
      });

      searchType.addEventListener('change', function() {
        performSearch();
      });

      // Initialize on page load
      function initializeTotals() {
        updateTotalPO();
        updateTotalAmount();
      }

      initializeTotals();

      // Observe table changes
      const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.type === 'childList' && mutation.target.classList.contains('table-responsive')) {
            initializeTotals();
          }
        });
      });

      const tableContainer = document.querySelector('.table-responsive');
      if (tableContainer) {
        observer.observe(tableContainer, {
          childList: true,
          subtree: true
        });
      }

      // Update totals after modal operations
      document.getElementById('addAccountModal').addEventListener('hidden.bs.modal', function() {
        window.location.reload();
      });

      document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        setTimeout(initializeTotals, 100);
      });

      function viewPurchaseOrder(data) {
        const company = data.purchase_order;
        const items = data.items;

        // Add toggle company button functionality
        const toggleCompanyBtn = document.getElementById('toggleCompanyBtn');
        const companyNameDisplay = document.getElementById('companyNameDisplay');
        const companyNameHeader = document.getElementById('companyNameHeader');
        const shipToCompany = document.querySelector('#viewPurchaseOrderModal td:nth-child(2) div:nth-child(2)');

        let isDefaultCompany = true;

        toggleCompanyBtn.addEventListener('click', function() {
          isDefaultCompany = !isDefaultCompany;
          const newCompanyName = isDefaultCompany ? 'JLQ Holdings Corporation' : 'JLQ Accounting Services';
          const newAddress = isDefaultCompany ?
            '3rd Flr. Annex Bldg. King`s Court, 2129 Chino Roces Ave. Pio Del Pillar Makati City' :
            '3rd Flr. Annex Bldg. King`s Court, 2129 Chino Roces Ave. Pio Del Pillar Makati City';

          companyNameDisplay.textContent = newCompanyName;
          companyNameHeader.textContent = newCompanyName;
          shipToCompany.innerHTML = `<b>Company Name:</b> ${newCompanyName}`;
        });

        document.getElementById('viewVendorCompany').textContent = company.company_name || '';
        document.getElementById('viewVendorAddress').textContent = company.street_address || '';
        document.getElementById('viewVendorCity').textContent = company.city || '';
        document.getElementById('viewVendorContact').textContent = company.contact_number || '';

        document.getElementById('viewRequisitioner').textContent = company.requisitioner || '';
        document.getElementById('viewShipVia').textContent = company.ship_via || '';
        document.getElementById('viewFob').textContent = company.fob || '';
        document.getElementById('viewShippingTerms').textContent = company.shipping_terms || '';

        document.getElementById('viewPoNumber').textContent = company.po_number || '';

        const date = new Date(company.date_created);
        const formattedDate = date.toLocaleDateString('en-US', {
          year: 'numeric',
          month: '2-digit',
          day: '2-digit'
        }).replace(/\//g, '/');
        document.getElementById('viewPoDate').textContent = formattedDate;

        const currencySymbol = company.currency === 'USD' ? '$' : '₱';

        const tbody = document.getElementById('viewItemsTableBody');
        tbody.innerHTML = '';

        items.forEach(item => {
          const row = document.createElement('tr');
          row.innerHTML = `
                    <td style="width: 15%;">${item.item_no}</td>
                    <td style="width: 35%;">${item.description}</td>
                    <td style="width: 10%;">${item.qty}</td>
                    <td style="width: 20%;">${currencySymbol}${parseFloat(item.unit_price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td style="width: 20%;">${currencySymbol}${parseFloat(item.total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                `;
          tbody.appendChild(row);
        });

        // Calculate subtotal from items
        const subtotal = items.reduce((sum, item) => sum + parseFloat(item.total), 0);
        const other = parseFloat(company.other || 0);

        // Add other amount to subtotal for gross
        const grossTotal = subtotal + other;

        // Calculate VAT and Vatable based on the gross total
        const vat = (grossTotal * 12) / 112;
        const vatable = grossTotal - vat;

        document.getElementById('viewSubtotal').textContent = `${currencySymbol}${grossTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('viewTax').textContent = `${currencySymbol}${vatable.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('viewShipping').textContent = `${currencySymbol}${vat.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('viewOther').textContent = `${currencySymbol}${other.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        document.getElementById('viewTotal').textContent = `${currencySymbol}${grossTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
      }

      document.querySelectorAll('.btn-success[data-bs-target="#viewPurchaseOrderModal"]').forEach(button => {
        button.addEventListener('click', function() {
          const row = this.closest('tr');
          const poId = row.getAttribute('data-id');

          fetch('?get_view_data=' + poId)
            .then(response => response.json())
            .then(data => {
              viewPurchaseOrder(data);
            })
            .catch(error => {
              console.error('Error:', error);
            });
        });
      });

      function printPurchaseOrder() {
        const style = document.createElement('style');
        style.id = 'print-style';
        style.innerHTML = `
                @media print {
                    @page {
                        size: portrait;
                        margin: 1cm;
                    }
                    * {
                        font-size: 25px;
                    }
                    body * {
                        visibility: hidden;
                    }

                    #toggleCompanyBtn {
                    display:none;
                    }
                    #printContent, #printContent * {
                        visibility: visible;
                    }
                    #printContent {
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 100%;
                    }
                    #printContent img {
                        visibility: visible !important;
                        display: inline-block !important;
                        width: 75px !important;
                        height: auto !important;
                        margin-right: 15px !important;
                    }
                    #printContent .table {
                        margin-bottom: 30px !important;
                    }
                    #printContent .table:last-of-type {
                        margin-bottom: 0 !important;
                    }
                    #printContent .table td, 
                    #printContent .table th {
                        padding: 15px !important;
                    }
                    .no-print {
                        display: none !important;
                    }
                    .currency-symbol {
                        font-weight: bold;
                    }
                }
            `;
        document.head.appendChild(style);

        const content = document.querySelector('#viewPurchaseOrderModal .modal-content').cloneNode(true);

        content.querySelector('.modal-header').remove();
        content.querySelector('.modal-footer').remove();

        const printContainer = document.createElement('div');
        printContainer.id = 'printContent';
        printContainer.style.padding = '20px';
        printContainer.style.fontFamily = 'Arial, sans-serif';

        // Create a new image element and wait for it to load
        const logoImg = new Image();
        logoImg.src = 'img/jlq-logo.png';

        logoImg.onload = function() {
          // Once the image is loaded, proceed with printing
          const existingLogo = content.querySelector('img');
          if (existingLogo) {
            existingLogo.src = 'img/jlq-logo.png';
            existingLogo.style.width = '75px';
            existingLogo.style.height = 'auto';
            existingLogo.style.display = 'inline-block';
            existingLogo.style.visibility = 'visible';
            existingLogo.style.marginRight = '15px';
          }

          printContainer.innerHTML = content.innerHTML;

          // Add spacing between tables
          const tables = printContainer.querySelectorAll('.table');
          tables.forEach((table, index) => {
            if (index < tables.length - 1) {
              table.style.marginBottom = '30px';
            }
          });

          const signatureDiv = document.createElement('div');
          signatureDiv.className = 'mt-5 d-flex justify-content-center';
          signatureDiv.style.marginTop = '100px';
          signatureDiv.innerHTML = `
                    <div class="d-flex gap-5">
                        <div class="text-center">
                            <div style="border-top: 1px solid #000; width: 200px; margin-bottom: 5px;"></div>
                            <div>Prepared By</div>
                        </div>
                        <div class="text-center">
                            <div style="border-top: 1px solid #000; width: 200px; margin-bottom: 5px;"></div>
                            <div>Checked By</div>
                        </div>
                    </div>
                `;

          printContainer.appendChild(signatureDiv);

          document.body.appendChild(printContainer);

          // Add a small delay to ensure everything is rendered
          setTimeout(() => {
            window.print();
            document.body.removeChild(printContainer);
            document.head.removeChild(style);
          }, 100);
        };

        // Handle image load error
        logoImg.onerror = function() {
          console.error('Failed to load logo image');
          // Proceed with printing even if logo fails to load
          printContainer.innerHTML = content.innerHTML;
          document.body.appendChild(printContainer);
          window.print();
          document.body.removeChild(printContainer);
          document.head.removeChild(style);
        };
      }

      // Add event listener for print button
      document.querySelector('#viewPurchaseOrderModal .btn-primary').addEventListener('click', function() {
        printPurchaseOrder();
      });

      document.querySelector('.table-responsive tbody').addEventListener('click', function(e) {
        if (e.target.closest('.btn-warning')) {
          const btn = e.target.closest('.btn-warning');
          const row = btn.closest('tr');
          const cancelModal = new bootstrap.Modal(document.getElementById('cancelConfirmationModal'));
          const confirmBtn = document.getElementById('confirmCancelBtn');
          const messageDiv = document.getElementById('cancelConfirmationMessage');
          const newConfirmBtn = confirmBtn.cloneNode(true);
          confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
          if (!row.classList.contains('cancelled-row')) {
            messageDiv.textContent = 'Are you sure you want to cancel this purchase order?';
          } else {
            messageDiv.textContent = 'Are you sure you want to remove the cancelled status?';
          }
          newConfirmBtn.onclick = function() {
            const willCancel = !row.classList.contains('cancelled-row');
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'toggleCancelPO=1&id=' + row.getAttribute('data-id') + '&cancel=' + (willCancel ? 1 : 0)
              })
              .then(res => res.json())
              .then(data => {
                if (!data.success) {
                  alert('Failed to update cancel status.');
                }
                window.location.reload();
              })
              .catch(() => {
                alert('Error updating cancel status.');
                window.location.reload();
              });
          };
          cancelModal.show();
        }
      });

      let pinAction = null;
      let pinPayload = null;

      function requestPin(action, payload) {
        pinAction = action;
        pinPayload = payload;
        document.getElementById('pinInput').value = '';
        document.getElementById('pinError').style.display = 'none';
        var pinModal = new bootstrap.Modal(document.getElementById('pinConfirmationModal'));
        pinModal.show();
        setTimeout(() => document.getElementById('pinInput').focus(), 300);
      }

      document.getElementById('confirmPinBtn').onclick = function() {
        const pin = document.getElementById('pinInput').value;
        if (pin === '1234') {
          bootstrap.Modal.getInstance(document.getElementById('pinConfirmationModal')).hide();
          if (pinAction === 'edit') {
            editPurchaseOrder(pinPayload);
          } else if (pinAction === 'delete') {
            deletePurchaseOrder(pinPayload);
          }
        } else {
          document.getElementById('pinError').style.display = 'block';
          document.getElementById('pinInput').classList.add('is-invalid');
          setTimeout(() => {
            document.getElementById('pinInput').classList.remove('is-invalid');
          }, 1000);
        }
      };

      // Optional: allow Enter key to submit PIN
      document.getElementById('pinInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          document.getElementById('confirmPinBtn').click();
        }
      });
    });
  </script>

</body>

</html>