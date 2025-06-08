/**
 * Sino de Notificação - Versão CORRIGIDA com seleção de usuários e grupos
 */

// Variáveis globais
let notificationCount = 0;
let unreadCount = 0;
let isBlinking = false;
let lastCheckTime = null;
let realNotifications = [];
let isLoading = false;
let markAllTimestamp = null;
let userGroups = [];
let userEntities = [];
let allowedUsers = [];
let allowedGroups = [];

$(document).ready(function() {
    // console.log('🔔 Plugin Sino de Notificação (USUÁRIOS E GRUPOS) iniciado');
    
    // Registra Service Worker
    registrarServiceWorker();
    solicitarPermissaoNotificacoes();
    
    // Adiciona sino
    adicionarSinoEstrutraGLPI();
    setTimeout(adicionarSinoEstrutraGLPI, 300);
    setTimeout(adicionarSinoEstrutraGLPI, 800);
    
    // EVENTOS DELEGADOS PARA BOTÕES CRIADOS DINAMICAMENTE
    setupEventosBotoes();
    
    // Carrega dados auxiliares
    carregarDadosAuxiliares();
    
    // Carrega notificações
    setTimeout(function() {
        // console.log('Iniciando carregamento de notificações específicas...');
        carregarNotificacoesReais();
    }, 1000);
    
    // Verifica novas notificações
    setInterval(verificarNovasNotificacoes, 20000);
});

/**
 * NOVA FUNÇÃO - Carrega dados auxiliares (usuários e grupos) - VERSÃO CORRIGIDA COMPLETA
 */
function carregarDadosAuxiliares() {
    // console.log('📊 Carregando usuários e grupos permitidos...');
    
    // Carrega usuários permitidos (mantém a versão que já funciona)
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'GET',
        data: { action: 'get_allowed_users' },
        dataType: 'json',
        timeout: 15000,
        success: function(response) {
            // console.log('📥 RESPOSTA USUÁRIOS:', response);
            
            if (response && response.success) {
                allowedUsers = response.users || [];
                // console.log('✅ Usuários carregados:', allowedUsers.length);
                
                if (allowedUsers.length === 0) {
                    setTimeout(() => carregarUsuariosForcado(), 2000);
                }
            } else {
                console.error('❌ Resposta inválida para usuários:', response);
                allowedUsers = [];
                setTimeout(() => carregarUsuariosForcado(), 3000);
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ ERRO ao carregar usuários:', { status, error, responseText: xhr.responseText });
            allowedUsers = [];
            if (status === 'timeout' || xhr.status >= 500) {
                setTimeout(() => carregarUsuariosForcado(), 5000);
            }
        }
    });
    
    // Carrega grupos técnicos permitidos - VERSÃO MELHORADA
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'GET',
        data: { action: 'get_allowed_groups' },
        dataType: 'json',
        timeout: 10000, // Aumenta timeout
        success: function(response) {
            // console.log('📥 RESPOSTA COMPLETA GRUPOS:', response);
            
            if (response && response.success) {
                allowedGroups = response.groups || [];
                // console.log('✅ Grupos carregados com sucesso:', allowedGroups.length);
                // console.log('📋 LISTA DE GRUPOS:', allowedGroups);
                
                if (response.debug) {
                    // console.log('🔍 DEBUG GRUPOS:', response.debug);
                }
                
                // Se ainda estiver vazio, força recarregamento
                if (allowedGroups.length === 0) {
                    // console.log('⚠️ Array de grupos vazio, tentando novamente...');
                    setTimeout(() => {
                        carregarGruposForcado();
                    }, 2000);
                }
            } else {
                console.error('❌ Resposta inválida para grupos:', response);
                allowedGroups = [];
                setTimeout(() => carregarGruposForcado(), 3000);
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ ERRO CRÍTICO ao carregar grupos:', {
                status: status,
                error: error,
                responseText: xhr.responseText,
                statusCode: xhr.status,
                readyState: xhr.readyState
            });
            
            allowedGroups = [];
            
            // Tenta novamente em caso de erro
            if (status === 'timeout' || xhr.status >= 500) {
                setTimeout(() => {
                    // console.log('🔄 Tentando recarregar grupos após erro...');
                    carregarGruposForcado();
                }, 5000);
            }
        }
    });
}

/**
 * NOVA FUNÇÃO - Força carregamento de grupos
 */
function carregarGruposForcado() {
    // console.log('🚀 CARREGAMENTO FORÇADO DE GRUPOS...');
    
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'POST',
        data: { 
            action: 'get_allowed_groups',
            force_reload: true,
            debug_mode: true
        },
        dataType: 'json',
        timeout: 20000,
        success: function(response) {
            // console.log('🔥 RESPOSTA CARREGAMENTO FORÇADO GRUPOS:', response);
            
            if (response && response.groups && response.groups.length > 0) {
                allowedGroups = response.groups;
                // console.log('✅ SUCESSO! Grupos carregados via método forçado:', allowedGroups.length);
                
                // Se o modal estiver aberto, atualiza o select
                if ($('#target-group').length > 0) {
                    populateGroupSelect();
                }
            } else {
                console.error('❌ Carregamento forçado de grupos também falhou:', response);
                
                // Como último recurso, adiciona grupos de teste
                allowedGroups = [
                    {
                        id: 999,
                        name: 'Grupo de Teste',
                        completename: 'Grupo de Teste - Verificar Configuração',
                        comment: 'Grupo de teste criado automaticamente',
                        users_count: 0,
                        source: 'fallback'
                    }
                ];
                // console.log('🆘 Usando grupos de fallback:', allowedGroups);
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ ERRO CRÍTICO no carregamento forçado de grupos:', {
                status: status,
                error: error,
                responseText: xhr.responseText
            });
            
            // Adiciona grupo de emergência
            allowedGroups = [
                {
                    id: 998,
                    name: 'Grupo de Emergência',
                    completename: 'Grupo de Emergência - Verificar Configuração do Sistema',
                    comment: 'Grupo criado automaticamente devido a erro de carregamento',
                    users_count: 0,
                    source: 'emergency'
                }
            ];
        }
    });
}

/**
 * NOVA FUNÇÃO - Força carregamento de usuários com método alternativo
 */
function carregarUsuariosForcado() {
    // console.log('🚀 CARREGAMENTO FORÇADO DE USUÁRIOS...');
    
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'POST', // Muda para POST
        data: { 
            action: 'get_allowed_users',
            force_reload: true,
            debug_mode: true
        },
        dataType: 'json',
        timeout: 20000,
        success: function(response) {
            // console.log('🔥 RESPOSTA CARREGAMENTO FORÇADO:', response);
            
            if (response && response.users && response.users.length > 0) {
                allowedUsers = response.users;
                // console.log('✅ SUCESSO! Usuários carregados via método forçado:', allowedUsers.length);
                
                // Se o modal estiver aberto, atualiza o select
                if ($('#target-user').length > 0) {
                    populateUserSelect();
                }
            } else {
                console.error('❌ Carregamento forçado também falhou:', response);
                
                // Como último recurso, adiciona usuários de teste
                allowedUsers = [
                    {
                        id: 999,
                        name: 'teste',
                        display_name: 'Usuário de Teste',
                        firstname: 'Teste',
                        realname: 'Sistema',
                        source: 'fallback'
                    }
                ];
                // console.log('🆘 Usando usuários de fallback:', allowedUsers);
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ ERRO CRÍTICO no carregamento forçado:', {
                status: status,
                error: error,
                responseText: xhr.responseText
            });
            
            // Adiciona usuário de emergência
            allowedUsers = [
                {
                    id: 998,
                    name: 'emergencia',
                    display_name: 'Usuário de Emergência - Verifique configuração',
                    firstname: 'Emergência',
                    realname: 'Sistema',
                    source: 'emergency'
                }
            ];
        }
    });
}

/**
 * NOVA FUNÇÃO - Setup de eventos delegados para botões
 */
function setupEventosBotoes() {
    // Evento delegado para botão de enviar notificação manual
    $(document).on('click', '.btn-enviar-manual', function(e) {
        e.preventDefault();
        e.stopPropagation();
        abrirModalEnvioManual(e);
    });
    
    // Evento delegado para botão de marcar todas como lidas
    $(document).on('click', '.btn-marcar-todas', function(e) {
        e.preventDefault();
        e.stopPropagation();
        marcarTodasComoLidas(e);
    });
    
    // Evento delegado para botão de atualizar
    $(document).on('click', '.btn-atualizar', function(e) {
        e.preventDefault();
        e.stopPropagation();
        atualizarNotificacoes(e);
    });
    
    // Evento delegado para botão de testar push - COMENTADO
    /*
    $(document).on('click', '.btn-testar-push', function(e) {
        e.preventDefault();
        e.stopPropagation();
        testarNotificacaoWindows(e);
    });
    */
    
    // Evento delegado para botões de marcar como lida individual
    $(document).on('click', '.mark-read-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const hash = $(this).closest('.notification-item').data('hash');
        marcarComoLida(hash, e);
    });
    
    // Evento delegado para fechar modal
    $(document).on('click', '.btn-fechar-modal', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('.modal-envio-manual').fadeOut(300, function() {
            $(this).remove();
        });
    });
    
    // NOVOS EVENTOS - Para seleção de tipo de destinatário
    $(document).on('change', '#target-type', function(e) {
        const targetType = $(this).val();
        atualizarCamposDestinatario(targetType);
    });
    // console.log('✅ Eventos delegados configurados com sucesso!');
}


/**
 * NOVA FUNÇÃO - Atualiza campos de destinatário baseado no tipo selecionado
 */
function atualizarCamposDestinatario(targetType) {
    const userField = $('#target-user-field');
    const groupField = $('#target-group-field');
    const infoText = $('.target-info-text');
    
    // Esconde todos os campos primeiro
    userField.hide();
    groupField.hide();
    
    // Remove listagem de usuários do grupo se existir
    $('#group-users-list').remove();
    
    switch (targetType) {
        case 'all':
            infoText.html('<i class="fas fa-users me-1 text-primary"></i><strong>Todos os usuários:</strong> A notificação será enviada para todos os usuários autorizados do sistema GLPI.');
            break;
            
        case 'user':
            userField.show();
            populateUserSelect();
            infoText.html('<i class="fas fa-user me-1 text-success"></i><strong>Usuário específico:</strong> A notificação será enviada apenas para o usuário selecionado.');
            break;
            
        case 'group':
            groupField.show();
            populateGroupSelect();
            infoText.html('<i class="fas fa-users-cog me-1 text-info"></i><strong>Grupo técnico:</strong> A notificação será enviada para todos os usuários do grupo técnico selecionado.');
            break;
    }
}

/**
 * NOVA FUNÇÃO - Carrega e exibe usuários de um grupo específico
 */
function carregarUsuariosDoGrupo(groupId) {
    if (!groupId || groupId === '') {
        $('#group-users-list').remove();
        return;
    }
    
    // Remove listagem anterior
    $('#group-users-list').remove();
    
    // Adiciona indicador de carregamento
    const loadingHtml = `
        <div id="group-users-list" class="mt-3 p-3 border rounded bg-light">
            <h6 class="mb-2">
                <i class="fas fa-users me-1"></i>
                Usuários que receberão a notificação:
            </h6>
            <div class="text-center">
                <i class="fas fa-spinner fa-spin me-2"></i>
                Carregando usuários do grupo...
            </div>
        </div>
    `;
    
    $('#target-group-field').after(loadingHtml);
    
    // Faz requisição para buscar usuários do grupo
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'GET',
        data: { 
            action: 'get_group_users',
            group_id: groupId
        },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            if (response.success && response.users) {
                exibirUsuariosDoGrupo(response);
            } else {
                exibirErroCarregamentoUsuarios(response.message || 'Erro desconhecido ao carregar usuários');
            }
        },
        error: function(xhr, status, error) {
            console.error('Erro ao carregar usuários do grupo:', error);
            exibirErroCarregamentoUsuarios('Erro de comunicação com o servidor');
        }
    });
}

/**
 * NOVA FUNÇÃO - Exibe a lista de usuários do grupo
 */
function exibirUsuariosDoGrupo(response) {
    const groupInfo = response.group_info || {};
    const users = response.users || [];
    const stats = response.stats || {};
    
    let usersHtml = '';
    
    if (users.length === 0) {
        usersHtml = `
            <div class="alert alert-warning small mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Nenhum usuário encontrado neste grupo técnico.
            </div>
        `;
    } else {
        // Separa usuários autorizados dos não autorizados
        const authorizedUsers = users.filter(user => user.has_authorized_profile);
        const limitedUsers = users.filter(user => !user.has_authorized_profile);
        
        usersHtml = '<div class="users-grid">';
        
        // Usuários autorizados
        if (authorizedUsers.length > 0) {
            usersHtml += '<div class="mb-3">';
            usersHtml += '<h6 class="text-success mb-2"><i class="fas fa-check-circle me-1"></i>Usuários autorizados (' + authorizedUsers.length + '):</h6>';
            usersHtml += '<div class="row">';
            
            authorizedUsers.forEach(user => {
                usersHtml += `
                    <div class="col-md-6 mb-1">
                        <span class="badge bg-success me-1">
                            <i class="fas fa-user me-1"></i>
                            ${user.display_name}
                        </span>
                    </div>
                `;
            });
            
            usersHtml += '</div></div>';
        }
        
        // Usuários com acesso limitado
        if (limitedUsers.length > 0) {
            usersHtml += '<div class="mb-2">';
            usersHtml += '<h6 class="text-warning mb-2"><i class="fas fa-exclamation-triangle me-1"></i>Usuários com acesso limitado (' + limitedUsers.length + '):</h6>';
            usersHtml += '<div class="row">';
            
            limitedUsers.forEach(user => {
                usersHtml += `
                    <div class="col-md-6 mb-1">
                        <span class="badge bg-warning text-dark me-1">
                            <i class="fas fa-user-times me-1"></i>
                            ${user.display_name}
                        </span>
                    </div>
                `;
            });
            
            usersHtml += '</div>';
            usersHtml += '<div class="alert alert-info small mb-0">';
            usersHtml += '<i class="fas fa-info-circle me-1"></i>';
            usersHtml += 'Usuários com acesso limitado podem não receber a notificação dependendo das configurações de perfil.';
            usersHtml += '</div>';
            usersHtml += '</div>';
        }
        
        usersHtml += '</div>';
        
        // Estatísticas
        usersHtml += `
            <div class="mt-3 p-2 bg-info bg-opacity-10 rounded">
                <strong>Resumo:</strong>
                <span class="ms-2">
                    <i class="fas fa-users me-1"></i>${stats.total_users} total
                </span>
                <span class="ms-3">
                    <i class="fas fa-check-circle text-success me-1"></i>${stats.authorized_users} autorizados
                </span>
                ${stats.limited_access_users > 0 ? `
                <span class="ms-3">
                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>${stats.limited_access_users} limitados
                </span>
                ` : ''}
            </div>
        `;
    }
    
    const finalHtml = `
        <div id="group-users-list" class="mt-3 p-3 border rounded" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
            <h6 class="mb-3 text-primary">
                <i class="fas fa-users me-1"></i>
                Usuários que receberão a notificação:
            </h6>
            ${groupInfo.name ? `
            <div class="mb-3 p-2 bg-primary bg-opacity-10 rounded">
                <strong>Grupo:</strong> ${groupInfo.completename || groupInfo.name}
                ${groupInfo.comment ? `<br><small class="text-muted">${groupInfo.comment}</small>` : ''}
            </div>
            ` : ''}
            ${usersHtml}
        </div>
    `;
    
    $('#group-users-list').replaceWith(finalHtml);
}

/**
 * NOVA FUNÇÃO - Exibe erro no carregamento dos usuários
 */
function exibirErroCarregamentoUsuarios(errorMessage) {
    const errorHtml = `
        <div id="group-users-list" class="mt-3 p-3 border rounded bg-light">
            <h6 class="mb-2 text-danger">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Erro ao carregar usuários:
            </h6>
            <div class="alert alert-danger small mb-0">
                ${errorMessage}
            </div>
        </div>
    `;
    
    $('#group-users-list').replaceWith(errorHtml);
}

/**
 * NOVA FUNÇÃO - Popula select de usuários - VERSÃO MELHORADA
 */
function populateUserSelect() {
    const select = $('#target-user');
    select.empty().append('<option value="">🔍 Selecione um usuário...</option>');
    
    // console.log('🔍 POPULANDO SELECT - allowedUsers:', allowedUsers);
    // console.log('🔍 Tipo de allowedUsers:', typeof allowedUsers);
    // console.log('🔍 É array?', Array.isArray(allowedUsers));
    // console.log('🔍 Tamanho:', allowedUsers.length);
    
    if (!allowedUsers || !Array.isArray(allowedUsers) || allowedUsers.length === 0) {
        select.append('<option value="" disabled>⚠️ Nenhum usuário encontrado - Recarregando...</option>');
        // console.log('⚠️ Array de usuários vazio ou inválido, forçando recarregamento...');
        
        // Força recarregamento
        setTimeout(() => {
            carregarUsuariosForcado();
        }, 1000);
        return;
    }
    
    let addedCount = 0;
    allowedUsers.forEach((user, index) => {
        if (user && user.id && user.display_name) {
            const userId = parseInt(user.id);
            const displayName = user.display_name.trim();
            
            if (userId > 0 && displayName) {
                select.append(`<option value="${userId}">👤 ${displayName}</option>`);
                addedCount++;
                // console.log(`👤 Adicionado: ${userId} - ${displayName} (fonte: ${user.source || 'unknown'})`);
            } else {
                console.warn(`⚠️ Usuário inválido no índice ${index}:`, user);
            }
        } else {
            console.warn(`⚠️ Objeto usuário inválido no índice ${index}:`, user);
        }
    });
    
    if (addedCount === 0) {
        select.append('<option value="" disabled>❌ Erro: Usuários carregados mas inválidos</option>');
        console.error('❌ Nenhum usuário válido foi adicionado ao select');
    } else {
        // console.log(`✅ Select populado com ${addedCount} usuários válidos`);
    }
}

/**
 * NOVA FUNÇÃO - Popula select de grupos - VERSÃO MELHORADA COM EVENTO DE MUDANÇA
 */
function populateGroupSelect() {
    const select = $('#target-group');
    select.empty().append('<option value="">🔍 Selecione um grupo técnico...</option>');
    
    if (!allowedGroups || !Array.isArray(allowedGroups) || allowedGroups.length === 0) {
        select.append('<option value="" disabled>⚠️ Nenhum grupo encontrado - Recarregando...</option>');
        
        // Força recarregamento
        setTimeout(() => {
            carregarGruposForcado();
        }, 1000);
        return;
    }
    
    let addedCount = 0;
    allowedGroups.forEach((group, index) => {
        if (group && group.id && group.name) {
            const groupId = parseInt(group.id);
            const groupName = group.name.trim();
            const userCount = group.users_count || 0;
            
            if (groupId > 0 && groupName) {
                const displayText = `👥 ${groupName} (${userCount} usuário${userCount !== 1 ? 's' : ''})`;
                select.append(`<option value="${groupId}">${displayText}</option>`);
                addedCount++;
            } else {
                console.warn(`⚠️ Grupo inválido no índice ${index}:`, group);
            }
        } else {
            console.warn(`⚠️ Objeto grupo inválido no índice ${index}:`, group);
        }
    });
    
    if (addedCount === 0) {
        select.append('<option value="" disabled>❌ Erro: Grupos carregados mas inválidos</option>');
        console.error('❌ Nenhum grupo válido foi adicionado ao select');
    }
    
    // ADICIONA EVENTO DE MUDANÇA PARA CARREGAR USUÁRIOS DO GRUPO
    select.off('change.groupUsers').on('change.groupUsers', function() {
        const selectedGroupId = $(this).val();
        if (selectedGroupId && selectedGroupId !== '') {
            carregarUsuariosDoGrupo(selectedGroupId);
        } else {
            $('#group-users-list').remove();
        }
    });
}

/**
 * Carrega notificações reais do GLPI
 */
function carregarNotificacoesReais() {
    if (isLoading) return;
    
    isLoading = true;
    // console.log('📡 Carregando notificações específicas do usuário...');
    
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'GET',
        data: { 
            limit: 100,
            action: 'get'
        },
        dataType: 'json',
        timeout: 8000,
        cache: false,
        success: function(response) {
            // console.log('📥 Resposta recebida:', response);
            
            if (response && response.success && response.notifications) {
                // console.log('✅ Sucesso! Total:', response.count, 'Não lidas:', response.unread_count);
                // console.log('👥 Grupos do usuário:', response.user_groups);
                // console.log('🏢 Entidades do usuário:', response.user_entities);
                
                // Armazena as notificações e dados do usuário
                realNotifications = response.notifications;
                notificationCount = response.count;
                unreadCount = response.unread_count;
                userGroups = response.user_groups || [];
                userEntities = response.user_entities || [];
                
                // Atualiza contador visual (apenas não lidas)
                atualizarContadorNotificacoes(unreadCount);
                
                // Pisca sino se há não lidas
                if (unreadCount > 0) {
                    iniciarPiscarSino();
                    setTimeout(pararPiscarSino, 3000);
                }
                
                // Envia notificações push para as não lidas mais recentes
                enviarNotificacoesPushNovas(response.notifications);
                
                // console.log('🎯 Notificações específicas processadas com sucesso');
                
            } else {
                console.error('❌ Resposta inválida:', response);
                usarDadosSimulados();
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ Erro na requisição:', status, error);
            if (xhr.status !== 0) {
                usarDadosSimulados();
            }
        },
        complete: function() {
            isLoading = false;
        }
    });
}

/**
 * Verifica novas notificações
 */
function verificarNovasNotificacoes() {
    if (isLoading) return;
    
    // console.log('🔄 Verificação de novas notificações...');
    
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/notifications.php',
        method: 'GET',
        data: { 
            limit: 50,
            action: 'get',
            since: lastCheckTime 
        },
        dataType: 'json',
        timeout: 5000,
        success: function(response) {
            if (response && response.success) {
                const novasNaoLidas = response.unread_count;
                
                if (novasNaoLidas > unreadCount) {
                    // console.log('🔔 Novas notificações não lidas:', novasNaoLidas - unreadCount);
                    
                    // Atualiza dados
                    const antigasNaoLidas = unreadCount;
                    notificationCount = response.count;
                    unreadCount = novasNaoLidas;
                    realNotifications = response.notifications;
                    
                    // Atualiza visual
                    atualizarContadorNotificacoes(unreadCount);
                    
                    // Pisca sino
                    iniciarPiscarSino();
                    setTimeout(pararPiscarSino, 5000);
                    
                    // Envia notificações push apenas para as novas
                    const novasNotificacoes = response.notifications.filter(n => !n.is_read).slice(0, novasNaoLidas - antigasNaoLidas);
                    enviarNotificacoesPushNovas(novasNotificacoes);
                }
                
                lastCheckTime = new Date().toISOString();
            }
        },
        error: function(xhr, status, error) {
            if (xhr.status !== 0) {
                console.error('❌ Erro na verificação:', error);
            }
        }
    });
}

/**
 * Envia notificações push para novas notificações - COM UTF-8 CORRETO
 */
function enviarNotificacoesPushNovas(notifications) {
    if (!notifications || notifications.length === 0) return;
    
    const novasNaoLidas = notifications.filter(n => !n.is_read);
    
    if (novasNaoLidas.length === 0) return;
    
    // console.log('📨 Enviando notificações push UTF-8 para', novasNaoLidas.length, 'novas notificações');
    
    // Envia uma notificação para cada nova notificação não lida
    novasNaoLidas.slice(0, 3).forEach((notif, index) => {
        setTimeout(() => {
            // Limpa HTML e garante UTF-8 correto
            const tituloLimpo = limparHtmlParaNotificacao(notif.titulo);
            const descricaoLimpa = limparHtmlParaNotificacao(notif.descricao);
            const usuarioLimpo = limparHtmlParaNotificacao(notif.usuario);
            
            enviarNotificacaoPush(
                `GLPI - ${tituloLimpo}`,
                `${descricaoLimpa}\n\nPor: ${usuarioLimpo} • ${notif.tempo}`,
                notif.link,
                notif.hash
            );
        }, index * 1000);
    });
    
    // Se há mais de 3, envia uma notificação resumo
    if (novasNaoLidas.length > 3) {
        setTimeout(() => {
            enviarNotificacaoPush(
                'GLPI - Múltiplas Notificações',
                `Você tem ${novasNaoLidas.length} novas notificações no sistema GLPI.`,
                '/front/central.php'
            );
        }, 4000);
    }
}

/**
 * Limpa HTML para notificações Windows - UTF-8
 */
function limparHtmlParaNotificacao(texto) {
    if (!texto) return '';
    
    // Remove tags HTML
    let limpo = texto.replace(/<[^>]*>/g, '');
    
    // Decodifica entidades HTML comuns
    limpo = limpo.replace(/&lt;/g, '<');
    limpo = limpo.replace(/&gt;/g, '>');
    limpo = limpo.replace(/&amp;/g, '&');
    limpo = limpo.replace(/&quot;/g, '"');
    limpo = limpo.replace(/&#39;/g, "'");
    limpo = limpo.replace(/&nbsp;/g, ' ');
    
    // Remove espaços extras
    limpo = limpo.replace(/\s+/g, ' ').trim();
    
    return limpo;
}

/**
 * Usa dados simulados quando há erro
 */
function usarDadosSimulados() {
    console.warn('⚠️ Usando dados simulados para notificações específicas');
    
    realNotifications = [
        {
            hash: 'simulado_001',
            tipo: 'sistema',
            icone: 'fas fa-exclamation-triangle',
            cor: 'text-warning',
            titulo: 'Plugin funcionando em modo simulado',
            descricao: 'Conecte ao banco de dados para ver notificações específicas do GLPI (followups e validações)',
            usuario: 'Sistema',
            tempo: 'agora',
            link: '/front/central.php',
            timestamp: new Date().toISOString(),
            is_read: false
        }
    ];
    
    notificationCount = realNotifications.length;
    unreadCount = realNotifications.filter(n => !n.is_read).length;
    atualizarContadorNotificacoes(unreadCount);
}

/**
 * Adiciona sino na estrutura GLPI
 */
function adicionarSinoEstrutraGLPI() {
    if ($('.sino-notificacao-glpi').length > 0) {
        return;
    }
    
    // console.log('🔧 Adicionando sino na estrutura GLPI...');
    
    var navbarContainer = $('.navbar.d-print-none.sticky-lg-top.shadow-sm.navbar-light.navbar-expand-md .container-fluid.flex-xl-nowrap.pe-xl-0');
    
    if (navbarContainer.length === 0) {
        navbarContainer = $('.navbar-light.navbar-expand-md .container-fluid');
    }
    
    if (navbarContainer.length === 0) {
        navbarContainer = $('.navbar .container-fluid');
    }
    
    if (navbarContainer.length === 0) {
        console.error('❌ Container navbar não encontrado');
        return;
    }
    
    var targetElement = navbarContainer.find('.ms-lg-auto.d-none.d-lg-block.flex-grow-1.flex-lg-grow-0');
    
    if (targetElement.length === 0) {
        targetElement = navbarContainer.find('.ms-lg-auto');
    }
    
    if (targetElement.length === 0) {
        targetElement = navbarContainer.find('.flex-grow-1');
    }
    
    if (targetElement.length === 0) {
        console.error('❌ Target element não encontrado');
        return;
    }
    
    targetElement.before(criarSinoElemento());
    // console.log('✅ Sino inserido com sucesso!');
}

/**
 * Cria elemento do sino
 */
function criarSinoElemento() {
    var sinoElement = $(`
        <div class="ms-2 me-2 sino-notificacao-glpi d-none d-lg-block">
            <button type="button" 
                    class="btn btn-sm btn-outline-warning sino-btn-notificacao" 
                    title="Notificações do Sistema GLPI"
                    aria-label="notificações DO GLPI">
               <i class="fas fa-bell text-warning sino-icon"></i>
               <span class="badge bg-danger sino-badge sino-number">0</span>
           </button>
       </div>
   `);
   
   sinoElement.find('.sino-btn-notificacao').on('click', function(e) {
       e.preventDefault();
       e.stopPropagation();
       
       // console.log('🔔 Sino clicado! Abrindo painel...');
       
       pararPiscarSino();
       
       $(this).find('i').addClass('sino-shake');
       setTimeout(() => {
           $(this).find('i').removeClass('sino-shake');
       }, 600);
       mostrarDropdownNotificacoesReais($(this));
  });
  
  return sinoElement;
}

function mostrarDropdownNotificacoesReais(botao) {
    // Remove dropdown existente
    $('.sino-dropdown-notificacoes').remove();
    
    // CONSTRÓI IMEDIATAMENTE com botão oculto, SEM verificação assíncrona
    construirDropdownComPerfil(false, botao, function() {
        // DEPOIS de construído, verifica perfil em background e atualiza apenas o botão
        verificarEAtualizarBotaoEnviar();
    });
}

/**
* NOVA FUNÇÃO - Verifica perfil ANTES de construir dropdown (sem piscada)
*/
function verificarPerfilAntesDeConstruir(botao) {
   $.ajax({
       url: '/plugins/sinodenotificacao/ajax/check_profile.php',
       method: 'GET',
       dataType: 'json',
       timeout: 3000,
       success: function(response) {
           // console.log('👤 Resposta do servidor sobre perfil:', response);
           
           var mostrarBotaoEnviar = false;
           
           if (response.success && response.profile_id) {
               var perfilAtual = parseInt(response.profile_id);
               var perfisPermitidos = [4, 172];
               mostrarBotaoEnviar = perfisPermitidos.includes(perfilAtual);
               
               // console.log('🔘 Perfil atual:', perfilAtual, '- Autorizado:', mostrarBotaoEnviar);
           }
           
           // AGORA SIM: constrói o dropdown com a informação correta
           construirDropdownComPerfil(mostrarBotaoEnviar, botao);
       },
       error: function(xhr, status, error) {
           console.error('❌ Erro ao verificar perfil:', error);
           // Em caso de erro, não mostra o botão
           construirDropdownComPerfil(false, botao);
       }
   });
}

/**
 * NOVA FUNÇÃO - Verifica perfil e atualiza apenas o botão, sem reconstruir dropdown
 */
function verificarEAtualizarBotaoEnviar() {
    $.ajax({
        url: '/plugins/sinodenotificacao/ajax/check_profile.php',
        method: 'GET',
        dataType: 'json',
        timeout: 3000,
        success: function(response) {
            var mostrarBotaoEnviar = false;
            
            if (response.success && response.profile_id) {
                var perfilAtual = parseInt(response.profile_id);
                var perfisPermitidos = [4, 172];
                mostrarBotaoEnviar = perfisPermitidos.includes(perfilAtual);
            }
            
            // ATUALIZA APENAS O BOTÃO, sem reconstruir o dropdown
            var headerButtons = $('.header-buttons');
            if (headerButtons.length > 0) {
                if (mostrarBotaoEnviar) {
                    // Se o botão não existe, adiciona
                    if ($('.btn-enviar-manual').length === 0) {
                        var botaoEnviarHtml = `
                            <button class="btn btn-sm btn-outline-success btn-enviar-manual me-1" 
                                    type="button"
                                    title="Enviar notificação manual"
                                    style="opacity: 0;">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        `;
                        headerButtons.prepend(botaoEnviarHtml);
                        
                        // Anima a entrada do botão
                        $('.btn-enviar-manual').animate({ opacity: 1 }, 300);
                    }
                } else {
                    // Se o botão existe mas não deveria, remove suavemente
                    $('.btn-enviar-manual').animate({ opacity: 0 }, 200, function() {
                        $(this).remove();
                    });
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ Erro ao verificar perfil:', error);
            // Em caso de erro, mantém botão oculto
            $('.btn-enviar-manual').animate({ opacity: 0 }, 200, function() {
                $(this).remove();
            });
        }
    });
}

/**
 * FUNÇÃO ATUALIZADA - Constrói dropdown com callback opcional
 */
function construirDropdownComPerfil(mostrarBotaoEnviar, botao, callback) {
    var notificacoesHtml = '';
    
    if (!realNotifications || realNotifications.length === 0) {
        notificacoesHtml = `
            <div class="text-center p-4">
                <i class="fas fa-bell-slash fa-2x text-muted mb-3"></i>
                <p class="text-muted">Nenhuma notificação recente</p>
            </div>
        `;
    } else {
        realNotifications.forEach(function(notif, index) {
            var linkInicio = notif.link ? `<a href="${notif.link}" class="text-decoration-none text-dark">` : '';
            var linkFim = notif.link ? '</a>' : '';
            var unreadClass = !notif.is_read ? 'unread' : '';
            
            // Destaque especial para notificações manuais COM DATA-PRIORITY
            var manualClass = notif.tipo === 'manual' ? 'manual-notification' : '';
            var priorityAttr = notif.tipo === 'manual' && notif.priority ? `data-priority="${notif.priority}"` : '';
            
            notificacoesHtml += `
                <div class="notification-item border-bottom py-2 px-2 ${unreadClass} ${manualClass}" 
                     data-hash="${notif.hash}" 
                     data-type="${notif.tipo}"
                     ${priorityAttr}>
                    ${linkInicio}
                    <div class="d-flex align-items-start position-relative">
                        <div class="notification-icon me-2 mt-1">
                            <i class="${notif.icone || 'fas fa-info-circle'} ${notif.cor || 'text-primary'}"></i>
                        </div>
                        <div class="notification-content flex-grow-1">
                            <div class="notification-title fw-semibold">${notif.titulo || 'Notificação'}</div>
                            <div class="notification-text text-muted small">${notif.descricao || 'Sem descrição'}</div>
                            <div class="notification-meta d-flex justify-content-between align-items-center mt-1">
                                <span class="notification-user text-muted small">por ${notif.usuario || 'Sistema'}</span>
                                <span class="notification-time text-muted small">${notif.tempo || 'agora'}</span>
                            </div>
                        </div>
                        ${!notif.is_read ? `
                        <button class="mark-read-btn" title="Marcar como lida" type="button">
                            <i class="fas fa-check"></i>
                        </button>
                        ` : ''}
                    </div>
                    ${linkFim}
                </div>
            `;
        });
    }
    
    // CONSTRÓI O HTML SEM BOTÃO DE ENVIAR (será adicionado depois se necessário)
    var botaoEnviarHtml = '';
    if (mostrarBotaoEnviar) {
        botaoEnviarHtml = `
            <button class="btn btn-sm btn-outline-success btn-enviar-manual me-1" 
                    type="button"
                    title="Enviar notificação manual">
                <i class="fas fa-paper-plane"></i>
            </button>
        `;
    }
    
    var dropdown = $(`
        <div class="sino-dropdown-notificacoes bg-white border rounded shadow-lg">
            <div class="dropdown-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">notificações DO GLPI</h6>
                    <div class="header-buttons">
                        ${botaoEnviarHtml}
                        <button class="btn btn-sm btn-outline-secondary btn-marcar-todas" 
                                type="button"
                                title="Marcar todas como lidas">
                            <i class="fas fa-check-double"></i>
                        </button>
                    </div>
                </div>
                <div class="notification-counters mt-2">
                    <span class="counter-unread">${unreadCount} não lidas</span>
                    <span class="counter-total">${notificationCount} total</span>
                </div>
            </div>
            
            <div class="notifications-container">
                ${notificacoesHtml}
            </div>
            
            <div class="dropdown-footer d-flex justify-content-between align-items-center">
                <button class="btn btn-sm btn-outline-primary btn-atualizar" 
                        type="button">
                    <i class="fas fa-sync me-1"></i> Atualizar
                </button>
                <!--
                <button class="btn btn-sm btn-outline-info btn-testar-push"
                        type="button">
                    <i class="fas fa-desktop me-1"></i> Testar Push
                </button>
                -->
            </div>
        </div>
    `);
    
    // Adiciona ao body para posicionamento responsivo
    $('body').append(dropdown);
    
    // Posiciona o dropdown
    posicionarDropdownResponsivo(botao, dropdown);
    
    // Remove dropdown ao clicar fora
    $(document).one('click', function(e) {
        if (!$(e.target).closest('.sino-dropdown-notificacoes, .sino-btn-notificacao, .modal-envio-manual').length) {
            $('.sino-dropdown-notificacoes').remove();
        }
    });
    
    // Remove ao pressionar ESC
    $(document).one('keydown', function(e) {
        if (e.key === 'Escape') {
            $('.sino-dropdown-notificacoes').remove();
            $('.modal-envio-manual').remove();
        }
    });
    
    // Animação de entrada SUAVE sem piscada
    dropdown.css({ opacity: 0, transform: 'translateY(-10px)' });
    dropdown.animate({ 
        opacity: 1 
    }, 300).css({ 
        transform: 'translateY(0)' 
    });
    
    // Executa callback se fornecido
    if (typeof callback === 'function') {
        setTimeout(callback, 50); // Pequeno delay para garantir que o DOM foi atualizado
    }
}

/**
* FUNÇÃO ATUALIZADA - Abre modal para envio de notificação manual
*/
function abrirModalEnvioManual(event) {
   if (event) {
       event.preventDefault();
       event.stopPropagation();
   }
   
   // console.log('📝 Abrindo modal de envio manual...');
   
   // Remove modal existente
   $('.modal-envio-manual').remove();
   
   const modal = $(`
       <div class="modal-envio-manual position-fixed" style="top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10000; display: flex; align-items: center; justify-content: center;">
           <div class="modal-content bg-white rounded shadow-lg p-4" style="width: 90%; max-width: 650px; max-height: 90vh; overflow-y: auto;">
               <div class="modal-header d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                   <h5 class="mb-0 fw-bold text-primary">
                       <i class="fas fa-paper-plane me-2"></i>
                       Enviar Notificação Manual
                   </h5>
                   <button class="btn btn-sm btn-outline-secondary btn-fechar-modal" type="button">
                       <i class="fas fa-times"></i>
                   </button>
               </div>
               
               <form id="form-envio-manual">
                   <div class="mb-3">
                       <label class="form-label fw-semibold">
                           <i class="fas fa-heading me-1"></i>
                           Título da Notificação
                       </label>
                       <input type="text" 
                              class="form-control" 
                              id="manual-title" 
                              placeholder="Ex: Manutenção programada, Aviso importante..."
                              maxlength="255" 
                              required>
                       <div class="form-text">Máximo 255 caracteres</div>
                   </div>
                   
                   <div class="mb-3">
                       <label class="form-label fw-semibold">
                           <i class="fas fa-comment me-1"></i>
                           Mensagem
                       </label>
                       <textarea class="form-control" 
          id="manual-message" 
          rows="5" 
          placeholder="Digite a mensagem que será enviada..."
          maxlength="100" 
          required></textarea>
<div class="form-text">Máximo 100 caracteres</div>
                   </div>
                   
                   <div class="row">
                       <div class="col-md-6 mb-3">
                           <label class="form-label fw-semibold">
                               <i class="fas fa-flag me-1"></i>
                               Prioridade
                           </label>
                           <select class="form-select" id="manual-priority">
                               <option value="low">📘 Baixa - Informação geral</option>
                               <option value="normal" selected>📗 Normal - Aviso padrão</option>
                               <option value="high">📙 Alta - Importante</option>
                               <option value="urgent">📕 Urgente - Crítico</option>
                           </select>
                       </div>
                       
                       <div class="col-md-6 mb-3">
                           <label class="form-label fw-semibold">
                               <i class="fas fa-bullseye me-1"></i>
                               Destinatário
                           </label>
                           <select class="form-select" id="target-type">
                               <option value="all">🌐 Todos os usuários</option>
                               <option value="user">👤 Usuário específico</option>
                               <option value="group">👥 Grupo técnico</option>
                           </select>
                       </div>
                   </div>
                   
                   <!-- Campo para seleção de usuário específico (hidden inicialmente) -->
                   <div id="target-user-field" class="mb-3" style="display: none;">
                       <label class="form-label fw-semibold">
                           <i class="fas fa-user me-1"></i>
                           Selecionar Usuário
                       </label>
                       <select class="form-select" id="target-user">
                           <option value="">🔍 Carregando usuários...</option>
                       </select>
                   </div>
                   
                   <!-- Campo para seleção de grupo técnico (hidden inicialmente) -->
                   <div id="target-group-field" class="mb-3" style="display: none;">
                       <label class="form-label fw-semibold">
                           <i class="fas fa-users-cog me-1"></i>
                           Selecionar Grupo Técnico
                       </label>
                       <select class="form-select" id="target-group">
                           <option value="">🔍 Carregando grupos...</option>
                       </select>
                   </div>
                   
                   <div class="alert alert-info small target-info-text">
                       <i class="fas fa-users me-1 text-primary"></i>
                       <strong>Todos os usuários:</strong> A notificação será enviada para todos os usuários autorizados do sistema GLPI.
                   </div>
                   
                   <div class="modal-footer d-flex justify-content-end gap-2 pt-3 border-top">
                       <button type="button" class="btn btn-secondary btn-fechar-modal">
                           <i class="fas fa-times me-1"></i> Cancelar
                       </button>
                       <button type="submit" class="btn btn-success btn-enviar-agora">
                           <i class="fas fa-paper-plane me-1"></i> Enviar Notificação
                       </button>
                   </div>
               </form>
           </div>
       </div>
   `);
   
   // EVENTOS DO MODAL - VIA EVENTOS DELEGADOS (já configurados)
   modal.find('#form-envio-manual').on('submit', function(e) {
       e.preventDefault();
       // console.log('📤 Enviando notificação manual...');
       enviarNotificacaoManualAgora();
   });
   
   // Contador de caracteres
   modal.find('#manual-title').on('input', function() {
       const current = $(this).val().length;
       const max = 255;
       $(this).next('.form-text').text(`${current}/${max} caracteres`);
       
       if (current > max - 20) {
           $(this).next('.form-text').addClass('text-warning');
       } else {
           $(this).next('.form-text').removeClass('text-warning');
       }
   });
   
   modal.find('#manual-message').on('input', function() {
    const current = $(this).val().length;
    const max = 100; // MUDANÇA AQUI: era 1000
    $(this).next('.form-text').text(`${current}/${max} caracteres`);
    
    if (current > max - 20) { // MUDANÇA: era max - 50
        $(this).next('.form-text').addClass('text-warning');
    } else {
        $(this).next('.form-text').removeClass('text-warning');
    }
});
   
   // Fecha ao clicar fora do modal
   modal.on('click', function(e) {
       if ($(e.target).hasClass('modal-envio-manual')) {
           // console.log('❌ Fechando modal (clique fora)...');
           modal.fadeOut(300, function() {
               $(this).remove();
           });
       }
   });
   
   // Adiciona modal ao DOM
   $('body').append(modal);
   modal.hide().fadeIn(300);
   
   // Inicia com tipo "all" selecionado
   setTimeout(() => {
       atualizarCamposDestinatario('all');
       modal.find('#manual-title').focus();
   }, 350);
   
   // console.log('✅ Modal de envio manual aberto com seleção de usuários/grupos!');
}

/**
* FUNÇÃO ATUALIZADA - Envia a notificação manual para o servidor
*/
function enviarNotificacaoManualAgora() {
   const title = $('#manual-title').val().trim();
   const message = $('#manual-message').val().trim();
   const priority = $('#manual-priority').val();
   const targetType = $('#target-type').val();
   const targetUserId = $('#target-user').val();
   const targetGroupId = $('#target-group').val();
   
   // console.log('📝 Dados do formulário:', { 
   //     title, 
   //     message, 
   //     priority, 
   //     targetType, 
   //     targetUserId, 
   //     targetGroupId 
   // });
   
   if (!title || !message) {
       alert('❌ Título e mensagem são obrigatórios!');
       return;
   }
   
   // Validações específicas por tipo de destinatário
   if (targetType === 'user' && !targetUserId) {
       alert('❌ Selecione um usuário específico!');
       return;
   }
   
   if (targetType === 'group' && !targetGroupId) {
       alert('❌ Selecione um grupo técnico!');
       return;
   }
   
   const btnEnviar = $('.btn-enviar-agora');
   const textOriginal = btnEnviar.html();
   
   // Feedback visual
   btnEnviar.html('<i class="fas fa-spinner fa-spin me-1"></i> Enviando...').prop('disabled', true);
   
   // Preparar dados para envio
   const postData = {
       action: 'send_manual',
       title: title,
       message: message,
       priority: priority,
       target_type: targetType
   };
   
   // Adiciona dados específicos baseado no tipo
   if (targetType === 'user' && targetUserId) {
       postData.target_user_id = targetUserId;
   }
   
   if (targetType === 'group' && targetGroupId) {
       postData.target_group_id = targetGroupId;
   }
   
   $.ajax({
       url: '/plugins/sinodenotificacao/ajax/notifications.php',
       method: 'POST',
       data: postData,
       dataType: 'json',
       timeout: 15000,
       success: function(response) {
           // console.log('✅ Resposta do servidor:', response);
           
           if (response.success) {
               // Sucesso!
               btnEnviar.html('<i class="fas fa-check me-1"></i> Enviado com Sucesso!')
                       .removeClass('btn-success')
                       .addClass('btn-success');
               
               // Mostra informações sobre o envio
               const recipientInfo = response.recipient_info || 'destinatários';
               const recipientCount = response.recipient_count || 0;
               
               // Fecha modal após 3 segundos
               setTimeout(() => {
                   $('.modal-envio-manual').fadeOut(300, function() {
                       $(this).remove();
                   });
                   
                   // Recarrega notificações para mostrar a nova
                   carregarNotificacoesReais();
                   
                   // Fecha dropdown atual para mostrar atualizado
                   $('.sino-dropdown-notificacoes').fadeOut(300, function() {
                       $(this).remove();
                   });
                   
                   // Mostra notificação push de confirmação
                   const confirmationTitle = `✅ GLPI - Notificação Enviada`;
                   let confirmationBody = '';
                   
                   switch (response.target_type) {
                       case 'all':
                           confirmationBody = `Sua notificação "${title}" foi enviada para todos os usuários autorizados (${recipientCount} usuários).`;
                           break;
                       case 'user':
                           confirmationBody = `Sua notificação "${title}" foi enviada para o usuário específico.`;
                           break;
                       case 'group':
                           confirmationBody = `Sua notificação "${title}" foi enviada para o grupo técnico (${recipientCount} usuários).`;
                           break;
                       default:
                           confirmationBody = `Sua notificação "${title}" foi enviada para ${recipientInfo}.`;
                   }
                   
                   enviarNotificacaoPush(confirmationTitle, confirmationBody, '/front/central.php');
                   
               }, 3000);
               
               // console.log('🎉 Notificação manual enviada com sucesso para:', response.recipient_info);
               
           } else {
               console.error('❌ Erro no servidor:', response.message);
               alert('❌ Erro ao enviar: ' + (response.message || 'Erro desconhecido'));
               btnEnviar.html(textOriginal).prop('disabled', false);
           }
       },
       error: function(xhr, status, error) {
           console.error('❌ Erro AJAX:', { xhr, status, error });
           alert('❌ Erro de comunicação com o servidor. Verifique sua conexão e tente novamente.');
           btnEnviar.html(textOriginal).prop('disabled', false);
       }
   });
}

/**
* FUNÇÃO CORRIGIDA - Atualizar notificações
*/
function atualizarNotificacoes(event) {
  if (event) {
      event.preventDefault();
      event.stopPropagation();
  }
  
  // console.log('🔄 Atualizando notificações...');
  
  const btn = $('.btn-atualizar');
  const originalText = btn.html();
  
  btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Atualizando...').prop('disabled', true);
  
  // Recarrega dados auxiliares também
  carregarDadosAuxiliares();
  
  // Fecha dropdown atual
  $('.sino-dropdown-notificacoes').fadeOut(200, function() {
      $(this).remove();
      
      // Recarrega notificações
      carregarNotificacoesReais();
      
      setTimeout(() => {
          // Reabre dropdown com dados atualizados
          const botaoSino = $('.sino-btn-notificacao');
          if (botaoSino.length > 0) {
              mostrarDropdownNotificacoesReais(botaoSino);
          }
      }, 1000);
  });
}

/**
* Posiciona dropdown de forma responsiva
*/
function posicionarDropdownResponsivo(botao, dropdown) {
 const windowWidth = $(window).width();
 const windowHeight = $(window).height();
 const botaoOffset = botao.offset();
 
 if (windowWidth <= 576) {
     // Mobile: ocupa quase toda a tela
     dropdown.css({
         position: 'fixed',
         top: '70px',
         left: '1rem',
         right: '1rem',
         width: 'auto',
         maxHeight: 'calc(100vh - 120px)'
     });
 } else if (windowWidth <= 768) {
     // Tablet: centralizado
     dropdown.css({
         position: 'fixed',
         top: '70px',
         left: '50%',
         transform: 'translateX(-50%)',
         width: '400px',
         maxHeight: 'calc(100vh - 120px)'
     });
 } else {
     // Desktop: próximo ao sino
     dropdown.css({
         position: 'fixed',
         top: (botaoOffset.top + 40) + 'px',
         right: (windowWidth - botaoOffset.left - botao.outerWidth()) + 'px',
         width: '450px',
         maxHeight: 'calc(100vh - ' + (botaoOffset.top + 60) + 'px)'
     });
 }
}

/**
* Marca notificação como lida - CORRIGIDA
*/
function marcarComoLida(hash, event) {
 if (event) {
     event.preventDefault();
     event.stopPropagation();
 }
 
 // console.log('✓ Marcando notificação como lida:', hash);
 
 // Feedback visual imediato
 const item = $(`.notification-item[data-hash="${hash}"]`);
 const button = item.find('.mark-read-btn');
 
 button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
 
 $.ajax({
     url: '/plugins/sinodenotificacao/ajax/notifications.php',
     method: 'POST',
     data: {
         action: 'mark_read',
         hash: hash
     },
     dataType: 'json',
     timeout: 5000,
     success: function(response) {
         // console.log('Resposta mark_read:', response);
         
         if (response.success) {
             // Atualiza visualmente
             item.removeClass('unread');
             button.remove();
             
             // Atualiza contadores globais
             unreadCount = Math.max(0, unreadCount - 1);
             atualizarContadorNotificacoes(unreadCount);
             
             // Atualiza contadores no dropdown
             $('.counter-unread').text(`${unreadCount} não lidas`);
             
             // Atualiza array local
             const notifIndex = realNotifications.findIndex(n => n.hash === hash);
             if (notifIndex >= 0) {
                 realNotifications[notifIndex].is_read = true;
             }
             
             // console.log('✅ Notificação marcada como lida com sucesso');
         } else {
             console.error('❌ Erro na resposta do servidor:', response);
             button.html('<i class="fas fa-exclamation-triangle"></i>').addClass('btn-danger');
         }
     },
     error: function(xhr, status, error) {
         console.error('❌ Erro AJAX ao marcar como lida:', error);
         button.html('<i class="fas fa-exclamation-triangle"></i>').addClass('btn-danger');
     }
 });
}

/**
* Marca todas as notificações como lidas - VERSÃO COMPLETAMENTE CORRIGIDA
*/
function marcarTodasComoLidas(event) {
  if (event) {
      event.preventDefault();
      event.stopPropagation();
  }
  
  // console.log('✓ Marcando TODAS as notificações como lidas...');
  
  // Feedback visual imediato
  const button = $('.btn-marcar-todas');
  const originalText = button.html();
  button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
  
  $.ajax({
      url: '/plugins/sinodenotificacao/ajax/notifications.php',
      method: 'POST',
      data: {
          action: 'mark_all_read'
      },
      dataType: 'json',
      timeout: 10000,
      success: function(response) {
          // console.log('Resposta mark_all_read:', response);
          
          if (response.success) {
              // Armazena timestamp para uso futuro
              markAllTimestamp = response.timestamp;
              
              // ZERA IMEDIATAMENTE TODOS OS CONTADORES
              unreadCount = 0;
              atualizarContadorNotificacoes(0);
              
              // Atualiza visualmente TODAS as notificações no dropdown
              $('.notification-item').removeClass('unread');
              $('.mark-read-btn').remove();
              
              // Atualiza contadores no dropdown
              $('.counter-unread').text('0 não lidas');
              
              // Atualiza array local - MARCA TODAS COMO LIDAS
              realNotifications.forEach(notif => {
                  notif.is_read = true;
              });
              
              // Para de piscar IMEDIATAMENTE
              pararPiscarSino();
              
              // LIMPA QUALQUER NOTIFICAÇÃO PUSH PENDENTE
              if ('serviceWorker' in navigator) {
                  navigator.serviceWorker.ready.then(function(registration) {
                      registration.getNotifications().then(function(notifications) {
                          notifications.forEach(function(notification) {
                              if (notification.tag && notification.tag.includes('glpi-notification')) {
                                  notification.close();
                              }
                          });
                          // console.log('🔔 Notificações push limpas');
                      });
                  });
              }
              
              // Feedback de sucesso
              button.html('<i class="fas fa-check"></i> Tudo Lido!')
                    .removeClass('btn-outline-secondary')
                    .addClass('btn-success');
              
              setTimeout(() => {
                  button.html(originalText)
                        .removeClass('btn-success')
                        .addClass('btn-outline-secondary')
                        .prop('disabled', false);
              }, 3000);
              
              // console.log('✅ TODAS as notificações marcadas como lidas e contadores zerados!');
              
              
          } else {
              console.error('❌ Erro na resposta do servidor:', response);
              button.html('<i class="fas fa-exclamation-triangle"></i> Erro')
                    .removeClass('btn-outline-secondary')
                    .addClass('btn-danger');
              
              setTimeout(() => {
                  button.html(originalText)
                        .removeClass('btn-danger')
                        .addClass('btn-outline-secondary')
                        .prop('disabled', false);
              }, 3000);
          }
      },
      error: function(xhr, status, error) {
          console.error('❌ Erro AJAX ao marcar todas como lidas:', error);
          button.html('<i class="fas fa-exclamation-triangle"></i> Erro de Conexão')
                .removeClass('btn-outline-secondary')
                .addClass('btn-danger');
          
          setTimeout(() => {
              button.html(originalText)
                    .removeClass('btn-danger')
                    .addClass('btn-outline-secondary')
                    .prop('disabled', false);
          }, 5000);
      }
  });
}

/**
* Funções auxiliares mantidas
*/
function iniciarPiscarSino() {
 if (isBlinking) return;
 
 isBlinking = true;
 const sinoIcon = $('.sino-icon');
 const sinoNumber = $('.sino-number');
 
 const blinkInterval = setInterval(() => {
     if (!isBlinking) {
         clearInterval(blinkInterval);
         sinoIcon.removeClass('sino-blink');
         sinoNumber.removeClass('number-blink');
         return;
     }
     
     sinoIcon.toggleClass('sino-blink');
     sinoNumber.toggleClass('number-blink');
 }, 500);
}

function pararPiscarSino() {
 isBlinking = false;
 $('.sino-icon').removeClass('sino-blink');
 $('.sino-number').removeClass('number-blink');
}

function atualizarContadorNotificacoes(numero) {
 $('.sino-number').text(numero);
 // console.log(`📊 Contador atualizado para: ${numero} não lidas`);
}

function registrarServiceWorker() {
 if ('serviceWorker' in navigator) {
     navigator.serviceWorker.register('/plugins/sinodenotificacao/sw.js')
         .then(function(registration) {
             // console.log('✅ Service Worker registrado com sucesso');
         })
         .catch(function(error) {
             console.error('❌ Erro ao registrar Service Worker:', error);
         });
 }
}

function solicitarPermissaoNotificacoes() {
 if ('Notification' in window && Notification.permission === 'default') {
     Notification.requestPermission().then(function(permission) {
         // console.log('🔔 Permissão para notificações:', permission);
         if (permission === 'granted') {
             // console.log('✅ Permissão concedida! Notificações push ativas.');
         }
     });
 }
}

/**
* ENVIA NOTIFICAÇÃO PUSH COM UTF-8 CORRETO
*/
function enviarNotificacaoPush(titulo, corpo, url = '/front/central.php', hash = null) {
 // console.log('📨 Enviando notificação push UTF-8:', titulo);
 
 if ('Notification' in window && Notification.permission === 'granted') {
     const notification = new Notification(titulo, {
         body: corpo,
         icon: '/plugins/sinodenotificacao/icon.png',
         badge: '/plugins/sinodenotificacao/icon.png',
         tag: 'glpi-notification-' + Date.now(),
         requireInteraction: false,
         silent: false,
         vibrate: [200, 100, 200],
         data: { 
             url: url,
             hash: hash
         },
         lang: 'pt-BR',
         dir: 'ltr'
     });
     
     notification.onclick = function() {
         window.focus();
         if (url && url !== '/front/central.php') {
             window.location.href = url;
         }
         notification.close();
     };
     
     setTimeout(() => notification.close(), 8000);
     // console.log('✅ Notificação push UTF-8 enviada:', titulo);
 } else {
     console.warn('❌ Permissão para notificações não concedida');
 }
}

/**
* FUNÇÃO PARA TESTAR NOTIFICAÇÃO - CORRIGIDA
*/
/*
function testarNotificacaoWindows(event) {
 if (event) {
     event.preventDefault();
     event.stopPropagation();
 }
 
 // console.log('🔔 Teste de notificação acionado!');
 
 const agora = new Date().toLocaleString('pt-BR', {
     day: '2-digit',
     month: '2-digit', 
     year: 'numeric',
     hour: '2-digit',
     minute: '2-digit',
     second: '2-digit'
 });
 
 enviarNotificacaoPush(
     'GLPI - Teste de Notificação Push 🔔',
     `Esta é uma notificação de teste do sistema GLPI.\n\n✅ UTF-8 funcionando corretamente!\n🕐 Horário: ${agora}\n\nSe você está vendo esta mensagem, as notificações push estão funcionando perfeitamente!`,
     '/front/central.php'
 );
 
 // console.log('🔔 Teste de notificação enviado!');
}*/

// Detecta mudanças na página
$(document).ajaxComplete(function() {
 setTimeout(adicionarSinoEstrutraGLPI, 200);
});

// Observer para mudanças no DOM
var observer = new MutationObserver(function(mutations) {
 let shouldUpdate = false;
 
 mutations.forEach(function(mutation) {
     if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
         for (let node of mutation.addedNodes) {
             if (node.nodeType === 1 && (
                 node.classList?.contains('navbar') || 
                 node.querySelector?.('.navbar') ||
                 node.classList?.contains('container-fluid')
             )) {
                 shouldUpdate = true;
                 break;
             }
         }
     }
 });
 
 if (shouldUpdate) {
     setTimeout(adicionarSinoEstrutraGLPI, 300);
 }
});

observer.observe(document.body, {
 childList: true,
 subtree: true
});

// Reposiciona dropdown ao redimensionar janela
$(window).on('resize', function() {
 const dropdown = $('.sino-dropdown-notificacoes');
 if (dropdown.length > 0) {
     const botao = $('.sino-btn-notificacao');
     if (botao.length > 0) {
         posicionarDropdownResponsivo(botao, dropdown);
     }
 }
});

// Expõe funções para debug - ATUALIZADO (APENAS PARA DESENVOLVIMENTO)
// Para produção, você pode comentar ou remover esta seção inteira
/*
window.debugSino = {
  carregarNotificacoes: carregarNotificacoesReais,
  verificarNovas: verificarNovasNotificacoes,
  testarNotificacao: testarNotificacaoWindows,
  marcarTodasLidas: marcarTodasComoLidas,
  abrirModalEnvio: abrirModalEnvioManual,
  enviarManual: enviarNotificacaoManualAgora,
  carregarDados: carregarDadosAuxiliares,
  testeBotoes: function() {
      console.log('🔧 Testando botões manualmente...');
      console.log('Botão enviar manual:', $('.btn-enviar-manual').length);
      console.log('Botão marcar todas:', $('.btn-marcar-todas').length);
      console.log('Botão atualizar:', $('.btn-atualizar').length);
      console.log('Botão testar push:', $('.btn-testar-push').length);
      
      // Força clique nos botões para teste
      $('.btn-enviar-manual').trigger('click');
  },
  notifications: function() { return realNotifications; },
  count: function() { return notificationCount; },
  unreadCount: function() { return unreadCount; },
  userGroups: function() { return userGroups; },
  userEntities: function() { return userEntities; },
  allowedUsers: function() { return allowedUsers; },
  allowedGroups: function() { return allowedGroups; },
  stats: function() {
      return {
          total: notificationCount,
          unread: unreadCount,
          read: notificationCount - unreadCount,
          isLoading: isLoading,
          isBlinking: isBlinking,
          markAllTimestamp: markAllTimestamp,
          userGroups: userGroups,
          userEntities: userEntities,
          allowedUsers: allowedUsers.length,
          allowedGroups: allowedGroups.length,
          lastCheck: lastCheckTime
      };
  }
};

console.log('🔔 Sistema de notificações DO GLPI USUÁRIOS E GRUPOS carregado!');
console.log('🛠️ Comandos disponíveis no console:');
console.log('   • window.debugSino.testarNotificacao() - Testa notificação Windows UTF-8');
console.log('   • window.debugSino.abrirModalEnvio() - Abre modal para envio manual');
console.log('   • window.debugSino.testeBotoes() - Testa todos os botões');
console.log('   • window.debugSino.userGroups() - Mostra grupos técnicos do usuário');
console.log('   • window.debugSino.userEntities() - Mostra entidades do usuário');
console.log('   • window.debugSino.allowedUsers() - Mostra usuários permitidos carregados');
console.log('   • window.debugSino.allowedGroups() - Mostra grupos permitidos carregados');
console.log('   • window.debugSino.stats() - Mostra estatísticas completas');
*/