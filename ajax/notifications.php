<?php
/**
 * Busca notificações específicas do GLPI com controle rigoroso de permissões por usuário
 * Cores das notificações manuais baseadas na prioridade + Correção do marcar todas como lidas
 * VERSÃO CORRIGIDA - HTML LIMPO NAS NOTIFICAÇÕES + ENVIO PARA USUÁRIOS/GRUPOS ESPECÍFICOS
 * + LISTAGEM DE USUÁRIOS DO GRUPO SELECIONADO
 */

include ("../../../inc/includes.php");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['glpiname']) || !isset($_SESSION['glpiID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado', 'success' => false]);
    exit;
}

$plugin = new Plugin();
if (!$plugin->isInstalled('sinodenotificacao') || !$plugin->isActivated('sinodenotificacao')) {
    http_response_code(404);
    echo json_encode(['error' => 'Plugin não ativo', 'success' => false]);
    exit;
}

try {
    global $DB;
    
    $user_id = (int)$_SESSION['glpiID'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'get';
    
    // Verifica se as tabelas existem, se não existem tenta criar
    createTablesIfNotExists();
    
    // Ação para buscar usuários permitidos
    if ($action === 'get_allowed_users') {
        $result = getAllowedUsers();
        echo json_encode($result);
        exit;
    }
    
    // Ação para buscar grupos técnicos permitidos
    if ($action === 'get_allowed_groups') {
        $result = getAllowedTechnicalGroups();
        echo json_encode($result);
        exit;
    }
    
    // NOVA AÇÃO - Buscar usuários de um grupo específico
    if ($action === 'get_group_users') {
        $group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : (isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0);
        
        if ($group_id > 0) {
            $result = getUsersFromSpecificGroup($group_id);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID do grupo inválido']);
        }
        exit;
    }
    
    // Ação para enviar notificação manual para todos os usuários
    if ($action === 'send_manual') {
        $result = sendManualNotification();
        echo json_encode($result);
        exit;
    }
    
    // Ação para marcar como lida
    if ($action === 'mark_read') {
        $notification_hash = $_POST['hash'] ?? '';
        if (!empty($notification_hash)) {
            $result = markNotificationAsRead($user_id, $notification_hash);
            echo json_encode([
                'success' => $result, 
                'message' => $result ? 'Notificação marcada como lida' : 'Erro ao marcar como lida'
            ]);
            exit;
        }
    }
    
    // Ação para marcar todas como lidas - CORRIGIDA
    if ($action === 'mark_all_read') {
        $result = markAllCurrentNotificationsAsRead($user_id);
        echo json_encode([
            'success' => $result !== false, 
            'message' => $result !== false ? 'Todas as notificações foram marcadas como lidas' : 'Erro ao marcar todas como lidas',
            'marked_count' => $result,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Buscar notificações específicas do usuário
    $notificacoes = [];
    $limite = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $since = $_GET['since'] ?? null;
    
    // Obter grupos técnicos do usuário
    $user_groups = getUserTechnicalGroups($user_id);
    $user_entities = getUserActiveEntities($user_id);
    
    // 1. NOTIFICAÇÕES MANUAIS (mantidas) - COM CORES POR PRIORIDADE
    $manual_notifications = getManualNotifications($user_id, $since);
    $notificacoes = array_merge($notificacoes, $manual_notifications);
    
    // 2. FOLLOWUPS em Tickets onde usuário tem permissão
    $ticket_followups = getTicketFollowupsForUser($user_id, $user_groups, $user_entities, $since);
    $notificacoes = array_merge($notificacoes, $ticket_followups);
    
    // 3. FOLLOWUPS em Problems onde usuário tem permissão
    $problem_followups = getProblemFollowupsForUser($user_id, $user_groups, $user_entities, $since);
    $notificacoes = array_merge($notificacoes, $problem_followups);
    
    // 4. FOLLOWUPS em Changes onde usuário tem permissão
    $change_followups = getChangeFollowupsForUser($user_id, $user_groups, $user_entities, $since);
    $notificacoes = array_merge($notificacoes, $change_followups);
    
    // 5. VALIDAÇÕES de Tickets
    $ticket_validations = getTicketValidationsForUser($user_id, $user_groups, $user_entities, $since);
    $notificacoes = array_merge($notificacoes, $ticket_validations);
    
    // 6. VALIDAÇÕES de Changes
    $change_validations = getChangeValidationsForUser($user_id, $user_groups, $user_entities, $since);
    $notificacoes = array_merge($notificacoes, $change_validations);
    
    // Ordena por timestamp (mais recente primeiro)
    usort($notificacoes, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // Verifica quais são lidas - MELHORADO
    $read_notifications = getReadNotifications($user_id);
    $unread_count = 0;
    
    foreach ($notificacoes as &$notif) {
        $notif['is_read'] = isNotificationRead($user_id, $notif['hash'], $notif['timestamp']);
        if (!$notif['is_read']) {
            $unread_count++;
        }
    }
    
    // Limita resultado
    $notificacoes = array_slice($notificacoes, 0, $limite);
    
    echo json_encode([
        'success' => true,
        'count' => count($notificacoes),
        'unread_count' => $unread_count,
        'notifications' => $notificacoes,
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user_id,
        'user_groups' => $user_groups,
        'user_entities' => $user_entities
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro no plugin sinodenotificacao: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// NOVAS FUNÇÕES PARA USUÁRIOS E GRUPOS
// ============================================================================

/**
 * NOVA FUNÇÃO - Busca usuários de um grupo específico com informações detalhadas
 */
function getUsersFromSpecificGroup($group_id) {
    global $DB;
    
    $users = [];
    
    try {
        // Primeira tentativa: busca via perfis autorizados
        $perfis_autorizados = [4, 172, 39, 28, 38, 35, 37, 36, 34];
        $perfis_sql = implode(',', $perfis_autorizados);
        
        $query = "
            SELECT DISTINCT u.id, u.name, u.firstname, u.realname, u.is_active,
                   pu.profiles_id,
                   CASE 
                       WHEN u.firstname IS NOT NULL AND u.firstname != '' AND u.realname IS NOT NULL AND u.realname != ''
                       THEN CONCAT(u.firstname, ' ', u.realname)
                       WHEN u.firstname IS NOT NULL AND u.firstname != ''
                       THEN u.firstname
                       WHEN u.realname IS NOT NULL AND u.realname != ''
                       THEN u.realname
                       ELSE u.name
                   END as display_name
            FROM glpi_users u
            INNER JOIN glpi_groups_users gu ON u.id = gu.users_id
            LEFT JOIN glpi_profiles_users pu ON u.id = pu.users_id
            WHERE gu.groups_id = " . (int)$group_id . "
              AND u.is_active = 1
              AND u.is_deleted = 0
              AND u.name != ''
              AND u.name IS NOT NULL
              AND (pu.profiles_id IN ($perfis_sql) OR pu.profiles_id IS NULL)
            ORDER BY display_name ASC
        ";
        
        $result = $DB->query($query);
        
        if ($result) {
            while ($row = $DB->fetchAssoc($result)) {
                $display_name = trim($row['display_name']);
                if (empty($display_name)) {
                    $display_name = $row['name'];
                }
                
                // Verifica se o usuário tem perfil autorizado
                $has_authorized_profile = false;
                if ($row['profiles_id']) {
                    $has_authorized_profile = in_array((int)$row['profiles_id'], $perfis_autorizados);
                }
                
                $users[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'display_name' => $display_name,
                    'firstname' => $row['firstname'],
                    'realname' => $row['realname'],
                    'profile_id' => (int)($row['profiles_id'] ?: 0),
                    'is_active' => (int)$row['is_active'],
                    'has_authorized_profile' => $has_authorized_profile,
                    'status' => $has_authorized_profile ? 'authorized' : 'limited_access'
                ];
            }
        }
        
        // Se não encontrou nenhum usuário, busca sem filtro de perfil
        if (empty($users)) {
            $query_fallback = "
                SELECT DISTINCT u.id, u.name, u.firstname, u.realname, u.is_active,
                       CASE 
                           WHEN u.firstname IS NOT NULL AND u.firstname != '' AND u.realname IS NOT NULL AND u.realname != ''
                           THEN CONCAT(u.firstname, ' ', u.realname)
                           WHEN u.firstname IS NOT NULL AND u.firstname != ''
                           THEN u.firstname
                           WHEN u.realname IS NOT NULL AND u.realname != ''
                           THEN u.realname
                           ELSE u.name
                       END as display_name
                FROM glpi_users u
                INNER JOIN glpi_groups_users gu ON u.id = gu.users_id
                WHERE gu.groups_id = " . (int)$group_id . "
                  AND u.is_active = 1
                  AND u.is_deleted = 0
                  AND u.name != ''
                  AND u.name IS NOT NULL
                ORDER BY display_name ASC
            ";
            
            $result_fallback = $DB->query($query_fallback);
            
            if ($result_fallback) {
                while ($row = $DB->fetchAssoc($result_fallback)) {
                    $display_name = trim($row['display_name']);
                    if (empty($display_name)) {
                        $display_name = $row['name'];
                    }
                    
                    $users[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'display_name' => $display_name,
                        'firstname' => $row['firstname'],
                        'realname' => $row['realname'],
                        'profile_id' => 0,
                        'is_active' => (int)$row['is_active'],
                        'has_authorized_profile' => false,
                        'status' => 'unknown_profile'
                    ];
                }
            }
        }
        
        // Busca informações do grupo para contexto
        $group_info = [];
        $group_query = "SELECT id, name, completename, comment FROM glpi_groups WHERE id = " . (int)$group_id;
        $group_result = $DB->query($group_query);
        
        if ($group_result && $group_row = $DB->fetchAssoc($group_result)) {
            $group_info = [
                'id' => (int)$group_row['id'],
                'name' => $group_row['name'],
                'completename' => $group_row['completename'] ?: $group_row['name'],
                'comment' => $group_row['comment']
            ];
        }
        
        // Estatísticas
        $total_users = count($users);
        $authorized_users = array_filter($users, function($user) {
            return $user['has_authorized_profile'];
        });
        $authorized_count = count($authorized_users);
        
        return [
            'success' => true,
            'group_id' => $group_id,
            'group_info' => $group_info,
            'users' => $users,
            'stats' => [
                'total_users' => $total_users,
                'authorized_users' => $authorized_count,
                'limited_access_users' => $total_users - $authorized_count
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Erro ao buscar usuários do grupo $group_id: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao buscar usuários do grupo: ' . $e->getMessage(),
            'group_id' => $group_id,
            'users' => [],
            'stats' => [
                'total_users' => 0,
                'authorized_users' => 0,
                'limited_access_users' => 0
            ]
        ];
    }
}

/**
 * Busca usuários permitidos (com perfis autorizados) - VERSÃO FINAL CORRIGIDA
 */
function getAllowedUsers() {
    global $DB;
    
    $users = [];
    $perfis_autorizados = [4, 172, 39, 28, 38, 35, 37, 36, 34];
    $perfis_sql = implode(',', $perfis_autorizados);
    
    try {
        // PRIMEIRA TENTATIVA: Busca via glpi_profiles_users (relacionamento many-to-many)
        $query1 = "
            SELECT DISTINCT u.id, u.name, u.firstname, u.realname, 
                   CASE 
                       WHEN u.firstname IS NOT NULL AND u.firstname != '' AND u.realname IS NOT NULL AND u.realname != ''
                       THEN CONCAT(u.firstname, ' ', u.realname)
                       WHEN u.firstname IS NOT NULL AND u.firstname != ''
                       THEN u.firstname
                       WHEN u.realname IS NOT NULL AND u.realname != ''
                       THEN u.realname
                       ELSE u.name
                   END as display_name,
                   pu.profiles_id
            FROM glpi_users u
            INNER JOIN glpi_profiles_users pu ON u.id = pu.users_id
            WHERE u.is_active = 1
              AND u.is_deleted = 0
              AND pu.profiles_id IN ($perfis_sql)
              AND u.name != ''
              AND u.name IS NOT NULL
            ORDER BY display_name ASC
            LIMIT 300
        ";
        
        $result1 = $DB->query($query1);
        
        if ($result1 && $DB->numrows($result1) > 0) {
            while ($row = $DB->fetchAssoc($result1)) {
                $display_name = trim($row['display_name']);
                if (empty($display_name)) {
                    $display_name = $row['name'];
                }
                
                $users[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'display_name' => $display_name,
                    'firstname' => $row['firstname'],
                    'realname' => $row['realname'],
                    'profile_id' => (int)$row['profiles_id'],
                    'source' => 'profiles_users'
                ];
            }
        } else {
            // SEGUNDA TENTATIVA: Busca via profiles_id direto na tabela users
            $query2 = "
                SELECT DISTINCT u.id, u.name, u.firstname, u.realname, u.profiles_id,
                       CASE 
                           WHEN u.firstname IS NOT NULL AND u.firstname != '' AND u.realname IS NOT NULL AND u.realname != ''
                           THEN CONCAT(u.firstname, ' ', u.realname)
                           WHEN u.firstname IS NOT NULL AND u.firstname != ''
                           THEN u.firstname
                           WHEN u.realname IS NOT NULL AND u.realname != ''
                           THEN u.realname
                           ELSE u.name
                       END as display_name
                FROM glpi_users u
                WHERE u.is_active = 1
                  AND u.is_deleted = 0
                  AND u.profiles_id IN ($perfis_sql)
                  AND u.name != ''
                  AND u.name IS NOT NULL
                ORDER BY display_name ASC
                LIMIT 300
            ";
            
            $result2 = $DB->query($query2);
            
            if ($result2 && $DB->numrows($result2) > 0) {
                while ($row = $DB->fetchAssoc($result2)) {
                    $display_name = trim($row['display_name']);
                    if (empty($display_name)) {
                        $display_name = $row['name'];
                    }
                    
                    $users[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'display_name' => $display_name,
                        'firstname' => $row['firstname'],
                        'realname' => $row['realname'],
                        'profile_id' => (int)$row['profiles_id'],
                        'source' => 'users_direct'
                    ];
                }
            } else {
                // TERCEIRA TENTATIVA: Busca todos os usuários ativos (para debug)
                $query3 = "
                    SELECT u.id, u.name, u.firstname, u.realname, u.profiles_id, u.is_active, u.is_deleted,
                           CASE 
                               WHEN u.firstname IS NOT NULL AND u.firstname != '' AND u.realname IS NOT NULL AND u.realname != ''
                               THEN CONCAT(u.firstname, ' ', u.realname)
                               WHEN u.firstname IS NOT NULL AND u.firstname != ''
                               THEN u.firstname
                               WHEN u.realname IS NOT NULL AND u.realname != ''
                               THEN u.realname
                               ELSE u.name
                           END as display_name
                    FROM glpi_users u
                    WHERE u.is_active = 1
                      AND u.is_deleted = 0
                      AND u.name != ''
                      AND u.name IS NOT NULL
                    ORDER BY display_name ASC
                    LIMIT 50
                ";
                
                $result3 = $DB->query($query3);
                
                if ($result3) {
                    $sample_count = 0;
                    while ($row = $DB->fetchAssoc($result3) && $sample_count < 10) {
                        // Se o perfil do usuário está na lista, adiciona mesmo assim
                        if (in_array((int)$row['profiles_id'], $perfis_autorizados)) {
                            $display_name = trim($row['display_name']);
                            if (empty($display_name)) {
                                $display_name = $row['name'];
                            }
                            
                            $users[] = [
                                'id' => (int)$row['id'],
                                'name' => $row['name'],
                                'display_name' => $display_name,
                                'firstname' => $row['firstname'],
                                'realname' => $row['realname'],
                                'profile_id' => (int)$row['profiles_id'],
                                'source' => 'general_filtered'
                            ];
                        }
                        $sample_count++;
                    }
                }
            }
        }
        
        // Se ainda não encontrou nenhum usuário, adiciona um usuário de teste
        if (count($users) === 0) {
            // Busca qualquer usuário ativo para teste
            $test_query = "
                SELECT u.id, u.name, u.firstname, u.realname 
                FROM glpi_users u 
                WHERE u.is_active = 1 AND u.is_deleted = 0 
                LIMIT 5
            ";
            
            $test_result = $DB->query($test_query);
            if ($test_result) {
                while ($row = $DB->fetchAssoc($test_result)) {
                    $users[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'display_name' => ($row['firstname'] ? $row['firstname'] . ' ' : '') . ($row['realname'] ?: $row['name']),
                        'firstname' => $row['firstname'],
                        'realname' => $row['realname'],
                        'profile_id' => 0,
                        'source' => 'test_user'
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ ERRO CRÍTICO ao buscar usuários: " . $e->getMessage());
        $users = [];
    }
    
    return [
        'success' => true,
        'users' => $users,
        'count' => count($users),
        'debug' => [
            'perfis_procurados' => $perfis_autorizados,
            'total_encontrados' => count($users),
            'query_executada' => $query1 ?? 'Nenhuma query executada',
            'metodo_utilizado' => !empty($users) ? $users[0]['source'] ?? 'desconhecido' : 'nenhum'
        ]
    ];
}

/**
 * Busca grupos técnicos permitidos - VERSÃO CORRIGIDA
 */
function getAllowedTechnicalGroups() {
    global $DB;
    
    $groups = [];
    $grupos_permitidos = [125, 124, 18, 14, 15, 10, 114, 5, 103, 6, 8, 9, 1, 7];
    $grupos_sql = implode(',', $grupos_permitidos);
    
    try {
        // PRIMEIRA TENTATIVA: Query com LEFT JOIN para contar usuários
        $query1 = "
            SELECT g.id, g.name, g.comment, g.completename, g.is_assign,
                   COUNT(DISTINCT gu.users_id) as users_count,
                   COUNT(DISTINCT u.id) as active_users_count
            FROM glpi_groups g
            LEFT JOIN glpi_groups_users gu ON g.id = gu.groups_id
            LEFT JOIN glpi_users u ON (gu.users_id = u.id AND u.is_active = 1 AND u.is_deleted = 0)
            WHERE g.id IN ($grupos_sql)
            GROUP BY g.id, g.name, g.comment, g.completename, g.is_assign
            ORDER BY g.name ASC
        ";
        
        $result1 = $DB->query($query1);
        
        if ($result1 && $DB->numrows($result1) > 0) {
            while ($row = $DB->fetchAssoc($result1)) {
                $groups[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'completename' => $row['completename'] ?: $row['name'],
                    'comment' => $row['comment'],
                    'users_count' => (int)$row['active_users_count'],
                    'is_assign' => (int)$row['is_assign'],
                    'source' => 'with_user_count'
                ];
            }
        } else {
            // SEGUNDA TENTATIVA: Query simples sem contagem de usuários
            $query2 = "
                SELECT g.id, g.name, g.comment, g.completename, g.is_assign
                FROM glpi_groups g
                WHERE g.id IN ($grupos_sql)
                ORDER BY g.name ASC
            ";
            
            $result2 = $DB->query($query2);
            
            if ($result2 && $DB->numrows($result2) > 0) {
                while ($row = $DB->fetchAssoc($result2)) {
                    // Para cada grupo, conta usuários separadamente
                    $user_count_query = "
                        SELECT COUNT(DISTINCT u.id) as count
                        FROM glpi_groups_users gu
                        JOIN glpi_users u ON (gu.users_id = u.id AND u.is_active = 1 AND u.is_deleted = 0)
                        WHERE gu.groups_id = " . (int)$row['id'];
                    
                    $user_count_result = $DB->query($user_count_query);
                    $user_count = 0;
                    
                    if ($user_count_result && $user_row = $DB->fetchAssoc($user_count_result)) {
                        $user_count = (int)$user_row['count'];
                    }
                    
                    $groups[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'completename' => $row['completename'] ?: $row['name'],
                        'comment' => $row['comment'],
                        'users_count' => $user_count,
                        'is_assign' => (int)$row['is_assign'],
                        'source' => 'simple_with_separate_count'
                    ];
                }
            } else {
                // TERCEIRA TENTATIVA: Verifica se os grupos existem na tabela
                $query3 = "
                    SELECT g.id, g.name, g.comment, g.completename
                    FROM glpi_groups g
                    WHERE g.id IN ($grupos_sql)
                    LIMIT 20
                ";
                
                $result3 = $DB->query($query3);
                
                if ($result3) {
                    $found_count = $DB->numrows($result3);
                    
                    if ($found_count > 0) {
                        while ($row = $DB->fetchAssoc($result3)) {
                            $groups[] = [
                                'id' => (int)$row['id'],
                                'name' => $row['name'],
                                'completename' => $row['completename'] ?: $row['name'],
                                'comment' => $row['comment'],
                                'users_count' => 0, // Não conseguiu contar
                                'is_assign' => 1,
                                'source' => 'verification_query'
                            ];
                        }
                    } else {
                        // QUARTA TENTATIVA: Busca alguns grupos existentes para debug
                        $debug_query = "SELECT id, name FROM glpi_groups ORDER BY name LIMIT 10";
                        $debug_result = $DB->query($debug_query);
                        
                        if ($debug_result) {
                            while ($debug_row = $DB->fetchAssoc($debug_result)) {
                                // Log apenas para debug, não adiciona aos grupos
                            }
                        }
                    }
                }
            }
        }
        
        // Se ainda não encontrou nenhum grupo, adiciona grupos de teste
        if (count($groups) === 0) {
            // Busca qualquer grupo existente para teste
            $test_query = "SELECT id, name FROM glpi_groups WHERE name IS NOT NULL AND name != '' ORDER BY name LIMIT 5";
            $test_result = $DB->query($test_query);
            
            if ($test_result) {
                while ($row = $DB->fetchAssoc($test_result)) {
                    $groups[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'] . ' (TESTE)',
                        'completename' => $row['name'] . ' (TESTE)',
                        'comment' => 'Grupo de teste - verifique configuração',
                        'users_count' => 0,
                        'is_assign' => 1,
                        'source' => 'test_group'
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ ERRO CRÍTICO ao buscar grupos: " . $e->getMessage());
        $groups = [];
    }
    
    return [
        'success' => true,
        'groups' => $groups,
        'count' => count($groups),
        'debug' => [
            'grupos_procurados' => $grupos_permitidos,
            'total_encontrados' => count($groups),
            'metodo_utilizado' => !empty($groups) ? $groups[0]['source'] ?? 'desconhecido' : 'nenhum'
        ]
    ];
}

/**
 * Busca usuários de um grupo específico com perfis autorizados
 */
function getUsersFromGroup($group_id) {
    global $DB;
    
    $users = [];
    $perfis_autorizados = [4, 172, 39, 28, 38, 35, 37, 36, 34];
    $perfis_sql = implode(',', $perfis_autorizados);
    
    try {
        $query = "
            SELECT DISTINCT u.id
            FROM glpi_users u
            JOIN glpi_groups_users gu ON u.id = gu.users_id
            JOIN glpi_profiles_users pu ON u.id = pu.users_id
            WHERE gu.groups_id = " . (int)$group_id . "
             AND u.is_active = 1
             AND u.is_deleted = 0
             AND pu.profiles_id IN ($perfis_sql)
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $users[] = (int)$row['id'];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar usuários do grupo: " . $e->getMessage());
   }
   
   return $users;
}

// ============================================================================
// FUNÇÕES ESPECÍFICAS PARA PERMISSÕES POR USUÁRIO - MANTIDAS
// ============================================================================

/**
* Obtém grupos técnicos do usuário
*/
function getUserTechnicalGroups($user_id) {
   global $DB;
   
   $groups = [];
   
   try {
       $query = "SELECT DISTINCT g.id, g.name 
                 FROM glpi_groups_users gu
                 JOIN glpi_groups g ON gu.groups_id = g.id
                 WHERE gu.users_id = $user_id 
                 AND g.is_deleted = 0";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $groups[] = (int)$row['id'];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar grupos do usuário: " . $e->getMessage());
   }
   
   return $groups;
}

/**
* Obtém entidades ativas do usuário
*/
function getUserActiveEntities($user_id) {
   $entities = [];
   
   // Entidade ativa atual
   if (isset($_SESSION['glpiactive_entity'])) {
       $entities[] = (int)$_SESSION['glpiactive_entity'];
   }
   
   // Todas as entidades ativas do usuário
   if (isset($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities'])) {
       $entities = array_merge($entities, $_SESSION['glpiactiveentities']);
   }
   
   return array_unique($entities);
}

/**
* Busca followups de tickets onde o usuário tem permissão - HTML CORRIGIDO
*/
function getTicketFollowupsForUser($user_id, $user_groups, $user_entities, $since = null) {
   global $DB;
   
   $notifications = [];
   
   if (empty($user_entities)) {
       return $notifications;
   }
   
   $entities_sql = implode(',', array_map('intval', $user_entities));
   $groups_sql = !empty($user_groups) ? implode(',', array_map('intval', $user_groups)) : '0';
   
   try {
       // Busca followups de tickets onde o usuário é requerente, observador ou técnico atribuído
       $query = "
           SELECT DISTINCT f.id as followup_id, f.content, f.date_creation, f.is_private,
                  f.items_id as ticket_id, f.users_id as author_id,
                  t.name as ticket_name, t.status as ticket_status, t.priority as ticket_priority,
                  u.name as author_name, u.firstname as author_firstname
           FROM glpi_itilfollowups f
           JOIN glpi_tickets t ON (f.itemtype = 'Ticket' AND f.items_id = t.id)
           LEFT JOIN glpi_users u ON f.users_id = u.id
           WHERE f.itemtype = 'Ticket'
             AND f.date_creation >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             AND t.is_deleted = 0
             AND t.entities_id IN ($entities_sql)
             AND f.is_private = 0
             AND (
                 -- Usuário é requerente
                 t.users_id_recipient = $user_id
                 OR
                 -- Usuário está na lista de requerentes
                 EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id = t.id AND tu.users_id = $user_id AND tu.type = 1)
                 OR
                 -- Usuário está na lista de observadores
                 EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id = t.id AND tu.users_id = $user_id AND tu.type = 3)
                 OR
                 -- Usuário é técnico atribuído
                 EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id = t.id AND tu.users_id = $user_id AND tu.type = 2)
                 OR
                 -- Grupo do usuário é atribuído
                 EXISTS(SELECT 1 FROM glpi_groups_tickets gt WHERE gt.tickets_id = t.id AND gt.groups_id IN ($groups_sql) AND gt.type = 2)
             )
           ORDER BY f.date_creation DESC
           LIMIT 30
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $hash = generateNotificationHash('ticket_followup', $row['followup_id'], $row['date_creation']);
               $tempo_diff = time() - strtotime($row['date_creation']);
               $tempo = formatarTempo($tempo_diff);
               
               $author_name = trim(($row['author_firstname'] ?? '') . ' ' . ($row['author_name'] ?? '')) ?: 'Sistema';
               $priority_color = getPriorityColor($row['ticket_priority']);
               
               // Limpa conteúdo do followup - VERSÃO CORRIGIDA
               $content_clean = html_entity_decode(strip_tags($row['content']), ENT_QUOTES, 'UTF-8');
               $content_clean = preg_replace('/\s+/', ' ', trim($content_clean));
               $description = mb_substr($content_clean, 0, 100) . (mb_strlen($content_clean) > 100 ? '...' : '');
               
               $notifications[] = [
                   'hash' => $hash,
                   'tipo' => 'ticket_followup',
                   'icone' => 'fas fa-comment-dots',
                   'cor' => $priority_color,
                   'titulo' => "Novo comentário no Ticket #{$row['ticket_id']}",
                   'descricao' => $description,
                   'usuario' => $author_name,
                   'tempo' => $tempo,
                   'link' => "/front/ticket.form.php?id={$row['ticket_id']}",
                   'timestamp' => $row['date_creation'],
                   'item_id' => $row['ticket_id'],
                   'item_type' => 'ticket',
                   'is_private' => $row['is_private']
               ];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar followups de tickets: " . $e->getMessage());
   }
   
   return $notifications;
}

/**
* Busca followups de problems onde o usuário tem permissão - HTML CORRIGIDO
*/
function getProblemFollowupsForUser($user_id, $user_groups, $user_entities, $since = null) {
   global $DB;
   
   $notifications = [];
   
   if (empty($user_entities)) {
       return $notifications;
   }
   
   $entities_sql = implode(',', array_map('intval', $user_entities));
   $groups_sql = !empty($user_groups) ? implode(',', array_map('intval', $user_groups)) : '0';
   
   try {
       $query = "
           SELECT DISTINCT f.id as followup_id, f.content, f.date_creation, f.is_private,
                  f.items_id as problem_id,
                  p.name as problem_name, p.status as problem_status, p.priority as problem_priority,
                  u.name as author_name, u.firstname as author_firstname
           FROM glpi_itilfollowups f
           JOIN glpi_problems p ON (f.itemtype = 'Problem' AND f.items_id = p.id)
           LEFT JOIN glpi_users u ON f.users_id = u.id
           WHERE f.itemtype = 'Problem'
             AND f.date_creation >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             AND p.is_deleted = 0
             AND p.entities_id IN ($entities_sql)
             AND (
                 -- Usuário está na lista de observadores
                 EXISTS(SELECT 1 FROM glpi_problems_users pu WHERE pu.problems_id = p.id AND pu.users_id = $user_id AND pu.type = 3)
                 OR
                 -- Usuário é técnico atribuído
                 EXISTS(SELECT 1 FROM glpi_problems_users pu WHERE pu.problems_id = p.id AND pu.users_id = $user_id AND pu.type = 2)
                 OR
                 -- Grupo do usuário é atribuído
                 EXISTS(SELECT 1 FROM glpi_groups_problems gp WHERE gp.problems_id = p.id AND gp.groups_id IN ($groups_sql) AND gp.type = 2)
             )
           ORDER BY f.date_creation DESC
           LIMIT 20
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $hash = generateNotificationHash('problem_followup', $row['followup_id'], $row['date_creation']);
               $tempo_diff = time() - strtotime($row['date_creation']);
               $tempo = formatarTempo($tempo_diff);
               
               $author_name = trim(($row['author_firstname'] ?? '') . ' ' . ($row['author_name'] ?? '')) ?: 'Sistema';
               $priority_color = getPriorityColor($row['problem_priority']);
               
               // Limpa conteúdo do followup - VERSÃO CORRIGIDA
               $content_clean = html_entity_decode(strip_tags($row['content']), ENT_QUOTES, 'UTF-8');
               $content_clean = preg_replace('/\s+/', ' ', trim($content_clean));
               $description = mb_substr($content_clean, 0, 100) . (mb_strlen($content_clean) > 100 ? '...' : '');
               
               $notifications[] = [
                   'hash' => $hash,
                   'tipo' => 'problem_followup',
                   'icone' => 'fas fa-tools',
                   'cor' => $priority_color,
                   'titulo' => "Novo comentário no Problema #{$row['problem_id']}",
                   'descricao' => $description,
                   'usuario' => $author_name,
                   'tempo' => $tempo,
                   'link' => "/front/problem.form.php?id={$row['problem_id']}",
                   'timestamp' => $row['date_creation'],
                   'item_id' => $row['problem_id'],
                   'item_type' => 'problem',
                   'is_private' => $row['is_private']
               ];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar followups de problems: " . $e->getMessage());
   }
   
   return $notifications;
}

/**
* Busca followups de changes onde o usuário tem permissão - HTML CORRIGIDO
*/
function getChangeFollowupsForUser($user_id, $user_groups, $user_entities, $since = null) {
   global $DB;
   
   $notifications = [];
   
   if (empty($user_entities)) {
       return $notifications;
   }
   
   $entities_sql = implode(',', array_map('intval', $user_entities));
   $groups_sql = !empty($user_groups) ? implode(',', array_map('intval', $user_groups)) : '0';
   
   try {
       $query = "
           SELECT DISTINCT f.id as followup_id, f.content, f.date_creation, f.is_private,
                  f.items_id as change_id,
                  c.name as change_name, c.status as change_status, c.priority as change_priority,
                  u.name as author_name, u.firstname as author_firstname
           FROM glpi_itilfollowups f
           JOIN glpi_changes c ON (f.itemtype = 'Change' AND f.items_id = c.id)
           LEFT JOIN glpi_users u ON f.users_id = u.id
           WHERE f.itemtype = 'Change'
             AND f.date_creation >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             AND c.is_deleted = 0
             AND c.entities_id IN ($entities_sql)
             AND (
                 -- Usuário é requerente
                 c.users_id_recipient = $user_id
                 OR
                 -- Usuário está na lista de observadores
                 EXISTS(SELECT 1 FROM glpi_changes_users cu WHERE cu.changes_id = c.id AND cu.users_id = $user_id AND cu.type = 3)
                 OR
                 -- Usuário é técnico atribuído
                 EXISTS(SELECT 1 FROM glpi_changes_users cu WHERE cu.changes_id = c.id AND cu.users_id = $user_id AND cu.type = 2)
                 OR
                 -- Grupo do usuário é atribuído
                 EXISTS(SELECT 1 FROM glpi_groups_changes gc WHERE gc.changes_id = c.id AND gc.groups_id IN ($groups_sql) AND gc.type = 2)
             )
           ORDER BY f.date_creation DESC
           LIMIT 20
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $hash = generateNotificationHash('change_followup', $row['followup_id'], $row['date_creation']);
               $tempo_diff = time() - strtotime($row['date_creation']);
               $tempo = formatarTempo($tempo_diff);
               
               $author_name = trim(($row['author_firstname'] ?? '') . ' ' . ($row['author_name'] ?? '')) ?: 'Sistema';
               $priority_color = getPriorityColor($row['change_priority']);
               
               // Limpa conteúdo do followup - VERSÃO CORRIGIDA
               $content_clean = html_entity_decode(strip_tags($row['content']), ENT_QUOTES, 'UTF-8');
               $content_clean = preg_replace('/\s+/', ' ', trim($content_clean));
               $description = mb_substr($content_clean, 0, 100) . (mb_strlen($content_clean) > 100 ? '...' : '');
               
               $notifications[] = [
                   'hash' => $hash,
                   'tipo' => 'change_followup',
                   'icone' => 'fas fa-exchange-alt',
                   'cor' => $priority_color,
                   'titulo' => "Novo comentário na Mudança #{$row['change_id']}",
                   'descricao' => $description,
                   'usuario' => $author_name,
                   'tempo' => $tempo,
                   'link' => "/front/change.form.php?id={$row['change_id']}",
                   'timestamp' => $row['date_creation'],
                   'item_id' => $row['change_id'],
                   'item_type' => 'change',
                   'is_private' => $row['is_private']
               ];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar followups de changes: " . $e->getMessage());
   }
   
   return $notifications;
}

/**
* Busca validações de tickets para o usuário
*/
function getTicketValidationsForUser($user_id, $user_groups, $user_entities, $since = null) {
   global $DB;
   
   $notifications = [];
   
   if (empty($user_entities)) {
       return $notifications;
   }
   
   $entities_sql = implode(',', array_map('intval', $user_entities));
   
   try {
       $query = "
           SELECT v.id as validation_id, v.status as validation_status, v.submission_date, v.validation_date,
                  v.tickets_id, v.users_id as requester_id, v.users_id_validate as validator_id,
                  t.name as ticket_name, t.status as ticket_status, t.priority as ticket_priority,
                  u1.name as requester_name, u1.firstname as requester_firstname,
                  u2.name as validator_name, u2.firstname as validator_firstname
           FROM glpi_ticketvalidations v
           JOIN glpi_tickets t ON v.tickets_id = t.id
           LEFT JOIN glpi_users u1 ON v.users_id = u1.id
           LEFT JOIN glpi_users u2 ON v.users_id_validate = u2.id
           WHERE t.is_deleted = 0
             AND t.entities_id IN ($entities_sql)
             AND v.submission_date >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
             AND (
                 -- Usuário solicitou a validação
                 v.users_id = $user_id
                 OR
                 -- Usuário é o validador
                 v.users_id_validate = $user_id
                 OR
                 -- Usuário está envolvido no ticket
                 EXISTS(SELECT 1 FROM glpi_tickets_users tu WHERE tu.tickets_id = t.id AND tu.users_id = $user_id)
             )
           ORDER BY v.submission_date DESC
           LIMIT 15
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $is_validator = ($row['validator_id'] == $user_id);
               $is_requester = ($row['requester_id'] == $user_id);
               
               // Determina o tipo de notificação
               if ($is_validator && $row['validation_status'] == 2) { // Waiting
                   $titulo = "Validação Pendente - Ticket #{$row['tickets_id']}";
                   $icone = 'fas fa-clock';
                   $cor = 'text-warning';
                   $timestamp = $row['submission_date'];
               } elseif ($is_requester && in_array($row['validation_status'], [3, 4])) { // Accepted or Refused
                   $titulo = $row['validation_status'] == 3 ? "Validação Aprovada - Ticket #{$row['tickets_id']}" : "Validação Rejeitada - Ticket #{$row['tickets_id']}";
                   $icone = $row['validation_status'] == 3 ? 'fas fa-check-circle' : 'fas fa-times-circle';
                   $cor = $row['validation_status'] == 3 ? 'text-success' : 'text-danger';
                   $timestamp = $row['validation_date'] ?: $row['submission_date'];
               } else {
                   continue; // Pula esta validação
               }
               
               $hash = generateNotificationHash('ticket_validation', $row['validation_id'], $timestamp);
               $tempo_diff = time() - strtotime($timestamp);
               $tempo = formatarTempo($tempo_diff);
               
               $requester_name = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_name'] ?? '')) ?: 'Sistema';
               $validator_name = trim(($row['validator_firstname'] ?? '') . ' ' . ($row['validator_name'] ?? '')) ?: 'Sistema';
               
               $usuario_display = $is_validator ? $requester_name : $validator_name;
               
               $notifications[] = [
                   'hash' => $hash,
                   'tipo' => 'ticket_validation',
                   'icone' => $icone,
                   'cor' => $cor,
                   'titulo' => $titulo,
                   'descricao' => "Ticket: " . mb_substr($row['ticket_name'], 0, 80),
                   'usuario' => $usuario_display,
                   'tempo' => $tempo,
                   'link' => "/front/ticket.form.php?id={$row['tickets_id']}",
                   'timestamp' => $timestamp,
                   'item_id' => $row['tickets_id'],
                   'item_type' => 'ticket',
                   'validation_status' => $row['validation_status']
               ];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar validações de tickets: " . $e->getMessage());
   }
   
   return $notifications;
}

/**
* Busca validações de changes para o usuário
*/
function getChangeValidationsForUser($user_id, $user_groups, $user_entities, $since = null) {
   global $DB;
   
   $notifications = [];
   
   if (empty($user_entities)) {
       return $notifications;
   }
   
   $entities_sql = implode(',', array_map('intval', $user_entities));
   
   try {
       $query = "
           SELECT v.id as validation_id, v.status as validation_status, v.submission_date, v.validation_date,
                  v.changes_id, v.users_id as requester_id, v.users_id_validate as validator_id,
                  c.name as change_name, c.status as change_status, c.priority as change_priority,
                  u1.name as requester_name, u1.firstname as requester_firstname,
                  u2.name as validator_name, u2.firstname as validator_firstname
           FROM glpi_changevalidations v
           JOIN glpi_changes c ON v.changes_id = c.id
           LEFT JOIN glpi_users u1 ON v.users_id = u1.id
           LEFT JOIN glpi_users u2 ON v.users_id_validate = u2.id
           WHERE c.is_deleted = 0
             AND c.entities_id IN ($entities_sql)
             AND v.submission_date >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
             AND (
                 -- Usuário solicitou a validação
                 v.users_id = $user_id
                 OR
                 -- Usuário é o validador
                 v.users_id_validate = $user_id
                 OR
                 -- Usuário está envolvido na mudança
                 EXISTS(SELECT 1 FROM glpi_changes_users cu WHERE cu.changes_id = c.id AND cu.users_id = $user_id)
             )
           ORDER BY v.submission_date DESC
           LIMIT 10
       ";
       
       $result = $DB->query($query);
       
       if ($result) {
           while ($row = $DB->fetchAssoc($result)) {
               $is_validator = ($row['validator_id'] == $user_id);
               $is_requester = ($row['requester_id'] == $user_id);
               
               // Determina o tipo de notificação
               if ($is_validator && $row['validation_status'] == 2) { // Waiting
                   $titulo = "Validação Pendente - Mudança #{$row['changes_id']}";
                   $icone = 'fas fa-clock';
                   $cor = 'text-warning';
                   $timestamp = $row['submission_date'];
               } elseif ($is_requester && in_array($row['validation_status'], [3, 4])) { // Accepted or Refused
                   $titulo = $row['validation_status'] == 3 ? "Validação Aprovada - Mudança #{$row['changes_id']}" : "Validação Rejeitada - Mudança #{$row['changes_id']}";
                   $icone = $row['validation_status'] == 3 ? 'fas fa-check-circle' : 'fas fa-times-circle';
                   $cor = $row['validation_status'] == 3 ? 'text-success' : 'text-danger';
                   $timestamp = $row['validation_date'] ?: $row['submission_date'];
               } else {
                   continue; // Pula esta validação
               }
               
               $hash = generateNotificationHash('change_validation', $row['validation_id'], $timestamp);
               $tempo_diff = time() - strtotime($timestamp);
               $tempo = formatarTempo($tempo_diff);
               
               $requester_name = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_name'] ?? '')) ?: 'Sistema';
               $validator_name = trim(($row['validator_firstname'] ?? '') . ' ' . ($row['validator_name'] ?? '')) ?: 'Sistema';
               
               $usuario_display = $is_validator ? $requester_name : $validator_name;
              
               $notifications[] = [
                   'hash' => $hash,
                   'tipo' => 'change_validation',
                   'icone' => $icone,
                   'cor' => $cor,
                   'titulo' => $titulo,
                   'descricao' => "Mudança: " . mb_substr($row['change_name'], 0, 80),
                   'usuario' => $usuario_display,
                   'tempo' => $tempo,
                   'link' => "/front/change.form.php?id={$row['changes_id']}",
                   'timestamp' => $timestamp,
                   'item_id' => $row['changes_id'],
                   'item_type' => 'change',
                   'validation_status' => $row['validation_status']
               ];
           }
       }
       
   } catch (Exception $e) {
       error_log("Erro ao buscar validações de changes: " . $e->getMessage());
   }
   
   return $notifications;
}

// ============================================================================
// FUNÇÕES AUXILIARES MELHORADAS
// ============================================================================

/**
* Cria tabelas se não existirem
*/
function createTablesIfNotExists() {
 global $DB;
 
 // Tabela de notificações lidas
 if (!$DB->tableExists('glpi_plugin_sinodenotificacao_read')) {
     $createQuery = "CREATE TABLE IF NOT EXISTS `glpi_plugin_sinodenotificacao_read` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `users_id` int(11) NOT NULL,
         `notification_hash` varchar(255) NOT NULL,
         `notification_type` varchar(50) NOT NULL,
         `item_id` int(11) NOT NULL,
         `date_read` datetime NOT NULL,
         `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `unique_read` (`users_id`, `notification_hash`),
         KEY `users_id` (`users_id`),
         KEY `notification_type` (`notification_type`),
         KEY `item_id` (`item_id`),
         KEY `date_read` (`date_read`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
     
     $DB->query($createQuery);
 }
 
 // Tabela de notificações manuais - ATUALIZADA COM NOVOS CAMPOS
 if (!$DB->tableExists('glpi_plugin_sinodenotificacao_manual')) {
     $createQuery = "CREATE TABLE IF NOT EXISTS `glpi_plugin_sinodenotificacao_manual` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `title` varchar(255) NOT NULL,
         `message` text NOT NULL,
         `sender_id` int(11) NOT NULL,
         `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
         `is_active` tinyint(1) DEFAULT 1,
         `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
         `icon` varchar(50) DEFAULT 'fas fa-bullhorn',
         `color` varchar(20) DEFAULT 'text-info',
         `target_type` enum('all','user','group') DEFAULT 'all',
         `target_user_id` int(11) NULL,
         `target_group_id` int(11) NULL,
         PRIMARY KEY (`id`),
         KEY `sender_id` (`sender_id`),
         KEY `date_creation` (`date_creation`),
         KEY `is_active` (`is_active`),
         KEY `target_type` (`target_type`),
         KEY `target_user_id` (`target_user_id`),
         KEY `target_group_id` (`target_group_id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
     
     $DB->query($createQuery);
 } else {
     // Atualiza tabela existente se necessário
     $fields = $DB->listFields('glpi_plugin_sinodenotificacao_manual');
     
     if (!isset($fields['target_type'])) {
         $DB->query("ALTER TABLE `glpi_plugin_sinodenotificacao_manual` ADD COLUMN `target_type` enum('all','user','group') DEFAULT 'all'");
     }
     
     if (!isset($fields['target_user_id'])) {
         $DB->query("ALTER TABLE `glpi_plugin_sinodenotificacao_manual` ADD COLUMN `target_user_id` int(11) NULL");
         $DB->query("ALTER TABLE `glpi_plugin_sinodenotificacao_manual` ADD INDEX `target_user_id` (`target_user_id`)");
     }
     
     if (!isset($fields['target_group_id'])) {
         $DB->query("ALTER TABLE `glpi_plugin_sinodenotificacao_manual` ADD COLUMN `target_group_id` int(11) NULL");
         $DB->query("ALTER TABLE `glpi_plugin_sinodenotificacao_manual` ADD INDEX `target_group_id` (`target_group_id`)");
     }
 }
 
 // Tabela de timestamp de "marcar todas como lidas"
 if (!$DB->tableExists('glpi_plugin_sinodenotificacao_read_all')) {
     $createQuery = "CREATE TABLE IF NOT EXISTS `glpi_plugin_sinodenotificacao_read_all` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `users_id` int(11) NOT NULL,
         `timestamp_marked` datetime NOT NULL,
         `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `unique_user` (`users_id`),
         KEY `timestamp_marked` (`timestamp_marked`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
     
     $DB->query($createQuery);
 }
}

/**
 * Envia notificação manual para todos os usuários ou específicos - VERSÃO ATUALIZADA COM LIMITE 100 CARACTERES
 */
function sendManualNotification() {
    global $DB;
    
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $target_type = $_POST['target_type'] ?? 'all';
    $target_user_id = !empty($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : null;
    $target_group_id = !empty($_POST['target_group_id']) ? (int)$_POST['target_group_id'] : null;
    $sender_id = (int)$_SESSION['glpiID'];
    
    // VALIDAÇÃO DE CAMPOS OBRIGATÓRIOS
    if (empty($title) || empty($message)) {
        return ['success' => false, 'message' => 'Título e mensagem são obrigatórios'];
    }
    
    // NOVA VALIDAÇÃO - LIMITE DE 100 CARACTERES PARA MENSAGEM
    if (mb_strlen($message) > 100) {
        return [
            'success' => false, 
            'message' => 'Mensagem deve ter no máximo 100 caracteres. Atual: ' . mb_strlen($message) . ' caracteres'
        ];
    }
    
    // VALIDAÇÃO DE TÍTULO (mantém 255 caracteres)
    if (mb_strlen($title) > 255) {
        return [
            'success' => false, 
            'message' => 'Título deve ter no máximo 255 caracteres. Atual: ' . mb_strlen($title) . ' caracteres'
        ];
    }
    
    // Validação do tipo de destinatário
    if ($target_type === 'user' && !$target_user_id) {
        return ['success' => false, 'message' => 'Usuário específico deve ser selecionado'];
    }
    
    if ($target_type === 'group' && !$target_group_id) {
        return ['success' => false, 'message' => 'Grupo técnico deve ser selecionado'];
    }
    
    try {
        $title_escaped = $DB->escape($title);
        $message_escaped = $DB->escape($message);
        $priority_escaped = $DB->escape($priority);
        $target_type_escaped = $DB->escape($target_type);
        
        $query = "INSERT INTO glpi_plugin_sinodenotificacao_manual 
                  (title, message, sender_id, priority, target_type, target_user_id, target_group_id, date_creation) 
                  VALUES ('$title_escaped', '$message_escaped', $sender_id, '$priority_escaped', '$target_type_escaped', " . 
                  ($target_user_id ? $target_user_id : 'NULL') . ", " . 
                  ($target_group_id ? $target_group_id : 'NULL') . ", NOW())";
        
        $result = $DB->query($query);
        
        if ($result) {
            $notification_id = $DB->insertId();
            
            // Determina quantos usuários receberão a notificação
            $recipient_count = 0;
            $recipient_info = '';
            
            switch ($target_type) {
                case 'all':
                    $recipient_info = 'todos os usuários do sistema';
                    // Busca quantos usuários autorizados existem
                    $count_result = getAllowedUsers();
                    $recipient_count = $count_result['count'] ?? 0;
                    break;
                    
                case 'user':
                    $recipient_info = 'usuário específico';
                    $recipient_count = 1;
                    break;
                    
                case 'group':
                    $group_users = getUsersFromGroup($target_group_id);
                    $recipient_count = count($group_users);
                    $recipient_info = "usuários do grupo técnico (total: $recipient_count)";
                    break;
            }
            
            return [
                'success' => true, 
                'message' => "Notificação enviada para $recipient_info com sucesso!",
                'notification_id' => $notification_id,
                'target_type' => $target_type,
                'recipient_count' => $recipient_count,
                'recipient_info' => $recipient_info,
                'character_count' => [
                    'title' => mb_strlen($title),
                    'message' => mb_strlen($message),
                    'title_limit' => 255,
                    'message_limit' => 100
                ]
            ];
        } else {
            return ['success' => false, 'message' => 'Erro ao salvar notificação no banco'];
        }
        
    } catch (Exception $e) {
        error_log("Erro ao enviar notificação manual: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()];
    }
}

/**
* Busca notificações manuais com filtro por usuário/grupo - VERSÃO ATUALIZADA
*/
function getManualNotifications($user_id, $since = null) {
 global $DB;
 
 $notifications = [];
 
 try {
     // Busca notificações manuais ativas que o usuário deve ver
     $query = "SELECT m.id, m.title, m.message, m.sender_id, m.date_creation, m.priority, m.icon, m.color,
                      m.target_type, m.target_user_id, m.target_group_id,
                      u.name as sender_name, u.firstname as sender_firstname
               FROM glpi_plugin_sinodenotificacao_manual m
               LEFT JOIN glpi_users u ON m.sender_id = u.id
               WHERE m.is_active = 1
                 AND (
                     -- Notificação para todos
                     m.target_type = 'all'
                     OR
                     -- Notificação para usuário específico
                     (m.target_type = 'user' AND m.target_user_id = $user_id)
                     OR
                     -- Notificação para grupo do usuário
                     (m.target_type = 'group' AND m.target_group_id IN (
                         SELECT gu.groups_id 
                         FROM glpi_groups_users gu 
                         WHERE gu.users_id = $user_id
                     ))
                 )
               ORDER BY m.date_creation DESC
               LIMIT 20";
     
     $result = $DB->query($query);
     
     if ($result) {
         while ($row = $DB->fetchAssoc($result)) {
             $hash = generateNotificationHash('manual', $row['id'], $row['date_creation']);
             $tempo_diff = time() - strtotime($row['date_creation']);
             $tempo = formatarTempo($tempo_diff);
             
             // CORES BASEADAS NA PRIORIDADE - CORRIGIDAS
             $priority_colors = [
                 'low' => 'text-info',        // Azul claro - Baixa
                 'normal' => 'text-primary',  // Azul - Normal  
                 'high' => 'text-warning',    // Laranja - Alta
                 'urgent' => 'text-danger'    // Vermelho - Urgente
             ];
             
             $priority_icons = [
                 'low' => 'fas fa-info-circle',
                 'normal' => 'fas fa-bullhorn',
                 'high' => 'fas fa-exclamation-triangle',
                 'urgent' => 'fas fa-exclamation-circle'
             ];
             
             $priority_prefixes = [
                 'low' => '📘',
                 'normal' => '📗',
                 'high' => '📙',
                 'urgent' => '📕'
             ];
             
             // Prefixos por tipo de destinatário
             $target_prefixes = [
                 'all' => '[GERAL]',
                 'user' => '[PESSOAL]',
                 'group' => '[GRUPO]'
             ];
             
             $color = $priority_colors[$row['priority']] ?? 'text-info';
             $icon = $priority_icons[$row['priority']] ?? 'fas fa-bullhorn';
             $prefix = $priority_prefixes[$row['priority']] ?? '📗';
             $target_prefix = $target_prefixes[$row['target_type']] ?? '[AVISO]';
             
             $sender_name = trim(($row['sender_firstname'] ?? '') . ' ' . ($row['sender_name'] ?? '')) ?: 'Administrador';
             
             $notifications[] = [
                 'hash' => $hash,
                 'tipo' => 'manual',
                 'icone' => $icon,
                 'cor' => $color,
                 'titulo' => "$prefix $target_prefix " . $row['title'],
                 'descricao' => mb_substr($row['message'], 0, 100) . (mb_strlen($row['message']) > 100 ? '...' : ''),
                 'usuario' => $sender_name,
                 'tempo' => $tempo,
                 'link' => '/front/central.php',
                 'timestamp' => $row['date_creation'],
                 'item_id' => $row['id'],
                 'item_type' => 'manual',
                 'priority' => $row['priority'],
                 'target_type' => $row['target_type'],
                 'full_message' => $row['message']
             ];
         }
     }
     
 } catch (Exception $e) {
     error_log("Erro ao buscar notificações manuais: " . $e->getMessage());
 }
 
 return $notifications;
}

function generateNotificationHash($type, $id, $timestamp) {
 return md5($type . '_' . $id . '_' . $timestamp);
}

function markNotificationAsRead($user_id, $hash) {
 global $DB;
 
 try {
     $hash_escaped = $DB->escape($hash);
     $query = "INSERT IGNORE INTO glpi_plugin_sinodenotificacao_read 
               (users_id, notification_hash, notification_type, item_id, date_read) 
               VALUES ($user_id, '$hash_escaped', 'mixed', 0, NOW())";
     
     $result = $DB->query($query);
     return $result !== false;
 } catch (Exception $e) {
     error_log("Erro ao marcar como lida: " . $e->getMessage());
     return false;
 }
}

/**
* Marca todas as notificações atuais como lidas - CORRIGIDA
*/
function markAllCurrentNotificationsAsRead($user_id) {
 global $DB;
 
 try {
     // Atualiza timestamp de "todas lidas" para este usuário
     $query = "INSERT INTO glpi_plugin_sinodenotificacao_read_all 
               (users_id, timestamp_marked) 
               VALUES ($user_id, NOW())
               ON DUPLICATE KEY UPDATE timestamp_marked = NOW()";
     
     $result = $DB->query($query);
     
     if ($result) {
         // Também marca individualmente todas as notificações atuais como lidas
         // para compatibilidade com verificações individuais
         $current_time = date('Y-m-d H:i:s');
         
         // Busca todas as notificações atuais que não estão marcadas como lidas
         $notifications = getAllCurrentNotificationsForUser($user_id);
         
         foreach ($notifications as $notif) {
             $hash_escaped = $DB->escape($notif['hash']);
             $insert_query = "INSERT IGNORE INTO glpi_plugin_sinodenotificacao_read 
                             (users_id, notification_hash, notification_type, item_id, date_read) 
                             VALUES ($user_id, '$hash_escaped', '{$notif['tipo']}', {$notif['item_id']}, '$current_time')";
             $DB->query($insert_query);
         }
         
         return count($notifications);
     }
     
     return false;
     
 } catch (Exception $e) {
     error_log("Erro ao marcar todas como lidas: " . $e->getMessage());
     return false;
 }
}

/**
* NOVA FUNÇÃO - Busca todas as notificações atuais para um usuário
*/
function getAllCurrentNotificationsForUser($user_id) {
 $notifications = [];
 
 // Busca os mesmos dados que a função principal usa
 $user_groups = getUserTechnicalGroups($user_id);
 $user_entities = getUserActiveEntities($user_id);
 
 // Busca todos os tipos de notificação
 $manual_notifications = getManualNotifications($user_id, null);
 $ticket_followups = getTicketFollowupsForUser($user_id, $user_groups, $user_entities, null);
 $problem_followups = getProblemFollowupsForUser($user_id, $user_groups, $user_entities, null);
 $change_followups = getChangeFollowupsForUser($user_id, $user_groups, $user_entities, null);
 $ticket_validations = getTicketValidationsForUser($user_id, $user_groups, $user_entities, null);
 $change_validations = getChangeValidationsForUser($user_id, $user_groups, $user_entities, null);
 
 // Junta todas
 $notifications = array_merge(
     $manual_notifications,
     $ticket_followups,
     $problem_followups,
     $change_followups,
     $ticket_validations,
     $change_validations
 );
 
 return $notifications;
}

/**
* NOVA FUNÇÃO - Verifica se uma notificação específica está lida considerando o timestamp global
*/
function isNotificationRead($user_id, $hash, $notification_timestamp) {
 global $DB;
 
 try {
     // Verifica se está marcada individualmente como lida
     $hash_escaped = $DB->escape($hash);
     $query = "SELECT id FROM glpi_plugin_sinodenotificacao_read 
               WHERE users_id = $user_id AND notification_hash = '$hash_escaped'";
     
     $result = $DB->query($query);
     if ($result && $DB->numrows($result) > 0) {
         return true; // Marcada individualmente como lida
     }
     
     // Verifica se está lida pelo timestamp global de "marcar todas"
     $query = "SELECT timestamp_marked FROM glpi_plugin_sinodenotificacao_read_all 
               WHERE users_id = $user_id";
     
     $result = $DB->query($query);
     if ($result && $row = $DB->fetchAssoc($result)) {
         $mark_all_timestamp = $row['timestamp_marked'];
         
         // Se a notificação foi criada antes do "marcar todas", considera como lida
         if (strtotime($notification_timestamp) <= strtotime($mark_all_timestamp)) {
             return true;
         }
     }
     
     return false;
     
 } catch (Exception $e) {
     error_log("Erro ao verificar se notificação está lida: " . $e->getMessage());
     return false;
 }
}

function getReadNotifications($user_id) {
 global $DB;
 
 try {
     // Busca notificações individuais marcadas como lidas
     $individual_read = [];
     $query = "SELECT notification_hash FROM glpi_plugin_sinodenotificacao_read WHERE users_id = $user_id";
     $result = $DB->query($query);
     
     if ($result) {
         while ($row = $DB->fetchAssoc($result)) {
             $individual_read[] = $row['notification_hash'];
         }
     }
     
     return $individual_read;
     
 } catch (Exception $e) {
     error_log("Erro ao buscar notificações lidas: " . $e->getMessage());
     return [];
 }
}

function formatarTempo($segundos) {
 if ($segundos < 60) {
     return 'agora mesmo';
 } elseif ($segundos < 3600) {
     $minutos = floor($segundos / 60);
     return "há {$minutos} minuto" . ($minutos > 1 ? 's' : '');
 } elseif ($segundos < 86400) {
     $horas = floor($segundos / 3600);
     return "há {$horas} hora" . ($horas > 1 ? 's' : '');
 } else {
     $dias = floor($segundos / 86400);
     return "há {$dias} dia" . ($dias > 1 ? 's' : '');
 }
}

function getPriorityColor($priority) {
 switch ((int)$priority) {
     case 1: return 'text-success';
     case 2: return 'text-info';
     case 3: return 'text-secondary';
     case 4: return 'text-warning';
     case 5: return 'text-danger';
     case 6: return 'text-danger';
     default: return 'text-muted';
 }
}

function getStatusText($status) {
 switch ((int)$status) {
     case 1: return 'Novo';
     case 2: return 'Em atendimento';
     case 3: return 'Planejado';
     case 4: return 'Pendente';
     case 5: return 'Resolvido';
     case 6: return 'Fechado';
     default: return 'Desconhecido';
 }
}

function getItemLink($itemtype, $id) {
 switch ($itemtype) {
     case 'Ticket': return "/front/ticket.form.php?id={$id}";
     case 'Problem': return "/front/problem.form.php?id={$id}";
     case 'Change': return "/front/change.form.php?id={$id}";
     default: return "/front/central.php";
 }
}
?>