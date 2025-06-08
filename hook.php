<?php
/**
 * Hooks de instalação com criação de tabelas - VERSÃO ATUALIZADA COM NOTIFICAÇÕES PARA USUÁRIOS/GRUPOS
 */

function plugin_sinodenotificacao_install() {
    global $DB;
    
    $success = true;
    
    // Tabela de notificações lidas
    $table1 = 'glpi_plugin_sinodenotificacao_read';
    if (!$DB->tableExists($table1)) {
        $query = "CREATE TABLE `$table1` (
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
        
        if ($DB->queryOrDie($query, "Erro ao criar tabela de notificações lidas")) {
            echo "✅ Tabela de notificações lidas criada com sucesso\n";
        } else {
            echo "❌ Erro ao criar tabela de notificações lidas\n";
            $success = false;
        }
    }
    
    // Tabela de notificações manuais - ATUALIZADA COM NOVOS CAMPOS
    $table2 = 'glpi_plugin_sinodenotificacao_manual';
    if (!$DB->tableExists($table2)) {
        $query = "CREATE TABLE `$table2` (
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
        
        if ($DB->queryOrDie($query, "Erro ao criar tabela de notificações manuais")) {
            echo "✅ Tabela de notificações manuais criada com sucesso\n";
        } else {
            echo "❌ Erro ao criar tabela de notificações manuais\n";
            $success = false;
        }
    }
    
    // Tabela de timestamp "marcar todas como lidas"
    $table3 = 'glpi_plugin_sinodenotificacao_read_all';
    if (!$DB->tableExists($table3)) {
        $query = "CREATE TABLE `$table3` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `users_id` int(11) NOT NULL,
            `timestamp_marked` datetime NOT NULL,
            `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user` (`users_id`),
            KEY `timestamp_marked` (`timestamp_marked`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($DB->queryOrDie($query, "Erro ao criar tabela de timestamp 'marcar todas'")) {
            echo "✅ Tabela de timestamp 'marcar todas' criada com sucesso\n";
        } else {
            echo "❌ Erro ao criar tabela de timestamp 'marcar todas'\n";
            $success = false;
        }
    }
    
    return $success;
}

function plugin_sinodenotificacao_uninstall() {
    global $DB;
    
    $tables = [
        'glpi_plugin_sinodenotificacao_read',
        'glpi_plugin_sinodenotificacao_manual', 
        'glpi_plugin_sinodenotificacao_read_all'
    ];
    
    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $query = "DROP TABLE `$table`";
            
            if ($DB->queryOrDie($query, "Erro ao remover tabela $table")) {
                echo "✅ Tabela $table removida com sucesso\n";
            } else {
                echo "❌ Erro ao remover tabela $table\n";
            }
        }
    }
    
    return true;
}

/**
 * Hook executado na atualização do plugin - VERSÃO ATUALIZADA
 */
function plugin_sinodenotificacao_update() {
    global $DB;
    
    // Atualiza tabela de notificações lidas
    $table1 = 'glpi_plugin_sinodenotificacao_read';
    if ($DB->tableExists($table1)) {
        $fields = $DB->listFields($table1);
        
        // Adiciona campo date_creation se não existir
        if (!isset($fields['date_creation'])) {
            $query = "ALTER TABLE `$table1` ADD COLUMN `date_creation` datetime DEFAULT CURRENT_TIMESTAMP";
            $DB->queryOrDie($query, "Erro ao adicionar campo date_creation");
            echo "✅ Campo date_creation adicionado à tabela read\n";
        }
        
        // Adiciona índices se não existirem
        try {
            $indexes = $DB->listKeys($table1);
            $indexNames = array_column($indexes, 'Key_name');
            
            if (!in_array('date_read', $indexNames)) {
                $query = "ALTER TABLE `$table1` ADD INDEX `date_read` (`date_read`)";
                $DB->queryOrDie($query, "Erro ao adicionar índice date_read");
                echo "✅ Índice date_read adicionado\n";
            }
        } catch (Exception $e) {
            // Ignora erros de índices duplicados
            echo "ℹ️ Índices já existem ou erro menor: " . $e->getMessage() . "\n";
        }
    }
    
    // Atualiza tabela de notificações manuais - NOVOS CAMPOS PARA USUÁRIOS/GRUPOS
    $table2 = 'glpi_plugin_sinodenotificacao_manual';
    if (!$DB->tableExists($table2)) {
        // Cria tabela completa se não existir
        $query = "CREATE TABLE `$table2` (
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
        
        $DB->queryOrDie($query, "Erro ao criar tabela de notificações manuais");
        echo "✅ Tabela de notificações manuais criada durante atualização\n";
    } else {
        // Atualiza tabela existente com novos campos
        $fields = $DB->listFields($table2);
        
        // Adiciona campo target_type se não existir
        if (!isset($fields['target_type'])) {
            $query = "ALTER TABLE `$table2` ADD COLUMN `target_type` enum('all','user','group') DEFAULT 'all' AFTER `color`";
            $DB->queryOrDie($query, "Erro ao adicionar campo target_type");
            echo "✅ Campo target_type adicionado à tabela manual\n";
            
            // Adiciona índice para target_type
            $query = "ALTER TABLE `$table2` ADD INDEX `target_type` (`target_type`)";
            $DB->queryOrDie($query, "Erro ao adicionar índice target_type");
            echo "✅ Índice target_type adicionado\n";
        }
        
        // Adiciona campo target_user_id se não existir
        if (!isset($fields['target_user_id'])) {
            $query = "ALTER TABLE `$table2` ADD COLUMN `target_user_id` int(11) NULL AFTER `target_type`";
            $DB->queryOrDie($query, "Erro ao adicionar campo target_user_id");
            echo "✅ Campo target_user_id adicionado à tabela manual\n";
            
            // Adiciona índice para target_user_id
            $query = "ALTER TABLE `$table2` ADD INDEX `target_user_id` (`target_user_id`)";
            $DB->queryOrDie($query, "Erro ao adicionar índice target_user_id");
            echo "✅ Índice target_user_id adicionado\n";
        }
        
        // Adiciona campo target_group_id se não existir
        if (!isset($fields['target_group_id'])) {
            $query = "ALTER TABLE `$table2` ADD COLUMN `target_group_id` int(11) NULL AFTER `target_user_id`";
            $DB->queryOrDie($query, "Erro ao adicionar campo target_group_id");
            echo "✅ Campo target_group_id adicionado à tabela manual\n";
            
            // Adiciona índice para target_group_id
            $query = "ALTER TABLE `$table2` ADD INDEX `target_group_id` (`target_group_id`)";
            $DB->queryOrDie($query, "Erro ao adicionar índice target_group_id");
            echo "✅ Índice target_group_id adicionado\n";
        }
        
        echo "✅ Tabela de notificações manuais atualizada com novos campos\n";
    }
    
    // Cria tabela de timestamp "marcar todas" se não existir
    $table3 = 'glpi_plugin_sinodenotificacao_read_all';
    if (!$DB->tableExists($table3)) {
        $query = "CREATE TABLE `$table3` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `users_id` int(11) NOT NULL,
            `timestamp_marked` datetime NOT NULL,
            `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user` (`users_id`),
            KEY `timestamp_marked` (`timestamp_marked`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $DB->queryOrDie($query, "Erro ao criar tabela de timestamp 'marcar todas'");
        echo "✅ Tabela de timestamp 'marcar todas' criada durante atualização\n";
    }
    
    return true;
}
?>