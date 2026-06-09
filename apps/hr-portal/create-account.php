<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$emp_id  = (int)($_POST['emp_id'] ?? 0);
$email   = clean($_POST['email'] ?? '');
$pass    = $_POST['password'] ?? '';

if (!$emp_id || !$email || !$pass) {
    header('Location: employees.php?msg=error'); exit;
}

$db = db();

// Check email not already taken
$exists = $db->prepare("SELECT id FROM users WHERE email=?");
$exists->execute([$email]); 
if ($exists->fetchColumn()) {
    header('Location: employees.php?view='.$emp_id.'&msg=email_taken'); exit;
}

// Get employee name
$emp = $db->prepare("SELECT first_name, last_name FROM employees WHERE id=?");
$emp->execute([$emp_id]); $emp = $emp->fetch();
if (!$emp) { header('Location: employees.php?msg=error'); exit; }

$name = $emp['first_name'].' '.$emp['last_name'];
$hash = password_hash($pass, PASSWORD_BCRYPT);

$db->prepare("INSERT INTO users (name,email,password,role,employee_id) VALUES (?,?,?,'employee',?)")
   ->execute([$name,$email,$hash,$emp_id]);

// Notify the employee
$newUserId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'success')")
   ->execute([$newUserId,'Welcome to Hambelela HR Portal','Your HR portal account has been created. You can now log in through the protected HR app in the Business Portal.']);

header('Location: employees.php?view='.$emp_id.'&msg=account_created'); exit;
