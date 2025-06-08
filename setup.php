<?php
/**
 * Plugin Sino de Notificação - Versão completa com RESTRIÇÃO POR PERFIL
 * Inclui Service Workers e Push Notifications
 * APENAS para perfis específicos: 4, 172, 39, 28, 38, 35, 37, 36, 34
 * 
 * @author Seu Nome
 * @version 2.1.1
 */

define('PLUGIN_SINODENOTIFICACAO_VERSION', '2.1.1');

// Perfis autorizados a usar o plugin
define('PLUGIN_SINODENOTIFICACAO_PERFIS_AUTORIZADOS', [4, 172, 39, 28, 38, 35, 37, 36, 34]);

/**
 * Verifica se o usuário atual tem perfil autorizado
 */
function plugin_sinodenotificacao_usuario_autorizado() {
    if (!isset($_SESSION['glpiactiveprofile']['id'])) {
        return false;
    }
    
    $perfil_atual = (int)$_SESSION['glpiactiveprofile']['id'];
    $perfis_autorizados = PLUGIN_SINODENOTIFICACAO_PERFIS_AUTORIZADOS;
    
    $autorizado = in_array($perfil_atual, $perfis_autorizados);
    
    error_log("Plugin Sino - Usuário ID: " . ($_SESSION['glpiID'] ?? 'N/A') . 
              ", Perfil: $perfil_atual, Autorizado: " . ($autorizado ? 'SIM' : 'NÃO'));
    
    return $autorizado;
}

/**
 * Inicialização do plugin
 */
function plugin_init_sinodenotificacao() {
    global $PLUGIN_HOOKS;
    
    $PLUGIN_HOOKS['csrf_compliant']['sinodenotificacao'] = true;
    
    // Só carrega se usuário logado E tem perfil autorizado
    if (isset($_SESSION['glpiname']) && plugin_sinodenotificacao_usuario_autorizado()) {
        $PLUGIN_HOOKS['add_javascript']['sinodenotificacao'] = ['js/sinodenotificacao.js'];
        $PLUGIN_HOOKS['add_css']['sinodenotificacao'] = ['css/sinodenotificacao.css'];
        
        // Adiciona meta tags para PWA/Service Worker
        $PLUGIN_HOOKS['add_header']['sinodenotificacao'] = 'plugin_sinodenotificacao_add_pwa_headers';
        
        // Hook para criar tabela de notificações lidas
        $PLUGIN_HOOKS['post_init']['sinodenotificacao'] = 'plugin_sinodenotificacao_post_init';
        
        // Log de carregamento autorizado
        error_log("Plugin Sino carregado para usuário autorizado: " . $_SESSION['glpiname']);
    } else {
        // Log de bloqueio
        if (isset($_SESSION['glpiname'])) {
            error_log("Plugin Sino BLOQUEADO para usuário: " . $_SESSION['glpiname'] . 
                     " (Perfil: " . ($_SESSION['glpiactiveprofile']['id'] ?? 'N/A') . ")");
        }
    }
}

/**
 * Inicialização pós carregamento
 */
function plugin_sinodenotificacao_post_init() {
    // Verifica novamente o perfil antes de criar tabelas
    if (plugin_sinodenotificacao_usuario_autorizado()) {
        plugin_sinodenotificacao_create_table();
    }
}

/**
 * Cria tabela para controlar notificações lidas - VERSÃO CORRIGIDA
 */
function plugin_sinodenotificacao_create_table() {
    global $DB;
    
    $table = 'glpi_plugin_sinodenotificacao_read';
    
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
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
        
        try {
            $DB->queryOrDie($query, "Erro ao criar tabela de notificações lidas");
            echo "✅ Tabela de notificações lidas criada com sucesso via setup\n";
        } catch (Exception $e) {
            error_log("Erro na criação da tabela sino notificações: " . $e->getMessage());
            echo "⚠️ Aviso: Tabela pode já existir ou erro menor: " . $e->getMessage() . "\n";
        }
    }
}

/**
 * Adiciona headers PWA para notificações - APENAS para perfis autorizados
 */
function plugin_sinodenotificacao_add_pwa_headers() {
    if (!plugin_sinodenotificacao_usuario_autorizado()) {
        return;
    }
    
    echo '<meta name="theme-color" content="#ffc107">';
    echo '<link rel="manifest" href="/plugins/sinodenotificacao/manifest.json">';
    echo '<link rel="icon" type="image/png" sizes="192x192" href="/plugins/sinodenotificacao/icon.png">';
    
    // Adiciona variável JavaScript com perfis autorizados
    echo '<script>';
    echo 'window.SINO_PERFIS_AUTORIZADOS = ' . json_encode(PLUGIN_SINODENOTIFICACAO_PERFIS_AUTORIZADOS) . ';';
    echo 'window.SINO_PERFIL_ATUAL = ' . ($_SESSION['glpiactiveprofile']['id'] ?? 'null') . ';';
    echo 'window.SINO_USUARIO_AUTORIZADO = ' . (plugin_sinodenotificacao_usuario_autorizado() ? 'true' : 'false') . ';';
    echo '</script>';
    
    // ADICIONA ELEMENTO HIDDEN COMO BACKUP
    echo '<input type="hidden" id="glpi-current-profile" value="' . ($_SESSION['glpiactiveprofile']['id'] ?? '') . '">';
}

/**
 * Versão do plugin
 */
function plugin_version_sinodenotificacao() {
    return [
        'name'           => 'Sino de Notificação (Perfis Restritos)',
        'version'        => PLUGIN_SINODENOTIFICACAO_VERSION,
        'author'         => 'Desenvolvedor',
        'license'        => 'GPL v3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => '10.0',
                'dev' => false
            ]
        ]
    ];
}

/**
 * Verificação de pré-requisitos
 */
function plugin_sinodenotificacao_check_prerequisites() {
    return version_compare(GLPI_VERSION, '10.0', '>=');
}

/**
 * Verificação de configuração
 */
function plugin_sinodenotificacao_check_config() {
    return true;
}
?>