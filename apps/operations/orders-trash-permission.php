<?php
declare(strict_types=1);

// This is the recoverable Live Orders action, not permission to purge Trash.
function ops_board_can_move_to_trash(): bool
{
    if (user_has_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff')) {
        return true;
    }
    return current_role_key() === 'marketing_sales' && (int) (current_user()['id'] ?? 0) === 15;
}
