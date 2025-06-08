<?php
/**
 * Verifica perfil do usuário atual
 */

include ("../../../inc/includes.php");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['glpiname']) || !isset($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado', 'success' => false]);
    exit;
}

try {
    $profile_id = null;
    
    // Busca o perfil ativo atual
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $profile_id = (int)$_SESSION['glpiactiveprofile']['id'];
    }
    
    echo json_encode([
        'success' => true,
        'profile_id' => $profile_id,
        'profile_name' => $_SESSION['glpiactiveprofile']['name'] ?? null,
        'user_id' => $_SESSION['glpiID'],
        'user_name' => $_SESSION['glpiname']
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro ao verificar perfil: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor'
    ]);
}
?>