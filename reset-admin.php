<?php
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/wp-load.php';

$username = 'admin_temp';
$password = 'TempPass123!';
$email    = 'temp@localhost.com';

$existing = get_user_by('login', $username);
if ($existing) {
    wp_set_password($password, $existing->ID);
    $user_id = $existing->ID;
    wp_update_user(['ID' => $user_id, 'role' => 'administrator']);
    echo "<p>Usuario actualizado. ID: $user_id</p>";
} else {
    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        echo "<p>Error: " . $user_id->get_error_message() . "</p>";
        exit;
    }
    $user = new WP_User($user_id);
    $user->set_role('administrator');
    echo "<p>Usuario creado. ID: $user_id</p>";
}

echo "<p><strong>Usuario:</strong> $username</p>";
echo "<p><strong>Contraseña:</strong> $password</p>";
echo "<p><a href='/wp-admin/'>Ir al admin</a></p>";
echo "<p style='color:red'><strong>IMPORTANTE: Elimina este archivo después de entrar.</strong></p>";
