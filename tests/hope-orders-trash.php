<?php
declare(strict_types=1);
require_once __DIR__ . '/../apps/operations/orders-trash-permission.php';
function current_user(): array { return $GLOBALS['actor']; }
function current_role_key(): string { return current_user()['role_key']; }
function user_has_role(string ...$roles): bool { return in_array(current_role_key(), $roles, true); }
foreach ([[15,'marketing_sales',true],[16,'marketing_sales',false],[15,'guest',false],[5,'front_desk_admin_employee',true],[7,'packer',true]] as [$id,$role,$expected]) {
    $GLOBALS['actor'] = ['id'=>$id,'role_key'=>$role];
    if (ops_board_can_move_to_trash() !== $expected) throw new RuntimeException('Incorrect Trash permission');
}
echo "Trash permission boundaries passed\n";
