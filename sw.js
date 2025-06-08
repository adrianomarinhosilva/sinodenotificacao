/**
 * Service Worker para notificações push do GLPI
 * Versão melhorada com suporte completo ao português brasileiro e notificações manuais
 */

self.addEventListener('install', function(event) {
    //console.log('Service Worker: Instalado com sucesso');
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    //console.log('Service Worker: Ativado e funcionando');
    event.waitUntil(self.clients.claim());
});

// Listener para notificações push - MELHORADO COM SUPORTE A NOTIFICAÇÕES MANUAIS
self.addEventListener('push', function(event) {
    if (event.data) {
        const data = event.data.json();
        
        // Configurações especiais para notificações manuais
        const isManual = data.type === 'manual';
        const priority = data.priority || 'normal';
        
        // Ícones diferentes baseados na prioridade
        let icon = '/plugins/sinodenotificacao/icon.png';
        let badge = '/plugins/sinodenotificacao/icon.png';
        let requireInteraction = false;
        let vibrate = [200, 100, 200, 100, 200];
        
        if (isManual) {
            switch (priority) {
                case 'urgent':
                    requireInteraction = true;
                    vibrate = [300, 100, 300, 100, 300, 100, 300];
                    break;
                case 'high':
                    requireInteraction = true;
                    vibrate = [250, 100, 250, 100, 250];
                    break;
                case 'normal':
                    vibrate = [200, 100, 200];
                    break;
                case 'low':
                    vibrate = [100, 50, 100];
                    break;
            }
        }
        
        const options = {
            body: data.body || 'Nova notificação do sistema GLPI',
            icon: icon,
            badge: badge,
            image: data.image,
            tag: data.tag || 'glpi-notification-' + Date.now(),
            requireInteraction: requireInteraction,
            silent: data.silent || false,
            vibrate: vibrate,
            timestamp: Date.now(),
            lang: 'pt-BR',
            data: {
                url: data.url || '/front/central.php',
                action: data.action || 'open',
                userId: data.userId,
                itemType: data.itemType || (isManual ? 'manual' : 'notification'),
                itemId: data.itemId,
                notificationHash: data.notificationHash,
                isManual: isManual,
                priority: priority
            },
            actions: [
                {
                    action: 'open',
                    title: 'Abrir GLPI',
                    icon: '/plugins/sinodenotificacao/icon.png'
                },
                {
                    action: 'mark_read',
                    title: 'Marcar como Lida',
                    icon: '/plugins/sinodenotificacao/icon.png'
                },
                {
                    action: 'close',
                    title: 'Fechar',
                    icon: '/plugins/sinodenotificacao/icon.png'
                }
            ]
        };
        
        // Título especial para notificações manuais
        let title = data.title || 'GLPI - Sistema de Gestão';
        if (isManual) {
            const priorityEmojis = {
                'urgent': '🚨',
                'high': '⚠️',
                'normal': '📢',
                'low': 'ℹ️'
            };
            title = `${priorityEmojis[priority] || '📢'} GLPI - ${data.title}`;
        }
        
        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    }
});

// Listener para cliques nas notificações - MELHORADO
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    const action = event.action;
    const data = event.notification.data;
    const url = data.url || '/front/central.php';
    
    if (action === 'close') {
        // Apenas fecha a notificação
        return;
    }
    
    if (action === 'mark_read' && data.notificationHash) {
        // Marca como lida via API
        event.waitUntil(
            fetch('/plugins/sinodenotificacao/ajax/notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=mark_read&hash=${data.notificationHash}`
            }).then(response => {
                //console.log('Notificação marcada como lida via Service Worker');
                
                // Para notificações manuais, registra interação especial
                if (data.isManual) {
                    //console.log('Notificação manual marcada como lida:', data.priority);
                }
            }).catch(error => {
                console.error('Erro ao marcar como lida:', error);
            })
        );
        return;
    }
    
    // Ação padrão: abrir ou focar janela do GLPI
    event.waitUntil(
        clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        }).then(function(clientList) {
            // Procura por uma janela do GLPI já aberta
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url.includes('glpi') && 'focus' in client) {
                    // Foca na janela existente
                    client.focus();
                    
                    // Navega para a URL específica se necessário
                    if (url && url !== '/front/central.php') {
                        client.navigate(url);
                    }
                    
                    // Para notificações manuais, envia mensagem especial
                    if (data.isManual) {
                        client.postMessage({
                            type: 'manual_notification_clicked',
                            priority: data.priority,
                            timestamp: Date.now()
                        });
                    }
                    
                    return;
                }
            }
            
            // Se não encontrou janela aberta, abre nova
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});

// Listener para fechar notificações
self.addEventListener('notificationclose', function(event) {
    //console.log('Notificação fechada pelo usuário:', event.notification.tag);
    
    const data = event.notification.data;
    if (data && data.isManual) {
        //console.log('Notificação manual fechada sem interação:', data.priority);
        
        // Opcional: registrar estatística de notificação manual fechada
        // sem ser lida, se necessário para estatísticas
    }
});

// Background sync para notificações offline
self.addEventListener('sync', function(event) {
    if (event.tag === 'background-sync-glpi-notifications') {
        event.waitUntil(
            // Sincroniza notificações quando volta online
            syncNotifications()
        );
    }
});

// Função para sincronizar notificações - MELHORADA
async function syncNotifications() {
    try {
        const response = await fetch('/plugins/sinodenotificacao/ajax/notifications.php?action=get&limit=50');
        
        if (response.ok) {
            const data = await response.json();
            //console.log('Notificações sincronizadas em background:', data.count);
            
            // Procura por notificações manuais urgentes
            const urgentManual = data.notifications?.filter(n => 
                n.tipo === 'manual' && 
                n.priority === 'urgent' && 
                !n.is_read
            ) || [];
            
            // Se há notificações urgentes, mostra notificação especial
            if (urgentManual.length > 0) {
                self.registration.showNotification('🚨 GLPI - Notificação Urgente', {
                    body: `Você tem ${urgentManual.length} notificação${urgentManual.length > 1 ? 'ões' : ''} urgente${urgentManual.length > 1 ? 's' : ''} do sistema.`,
                    icon: '/plugins/sinodenotificacao/icon.png',
                    tag: 'glpi-urgent-sync',
                    requireInteraction: true,
                    vibrate: [300, 100, 300, 100, 300, 100, 300],
                    data: { 
                        url: '/front/central.php',
                        isManual: true,
                        priority: 'urgent'
                    }
                });
            } else if (data.unread_count > 0) {
                // Notificação resumo normal
                self.registration.showNotification('GLPI - Notificações Sincronizadas', {
                    body: `Você tem ${data.unread_count} notificação${data.unread_count > 1 ? 'ões' : ''} não lida${data.unread_count > 1 ? 's' : ''} no sistema.`,
                    icon: '/plugins/sinodenotificacao/icon.png',
                    tag: 'glpi-sync-notification',
                    data: { url: '/front/central.php' }
                });
            }
        }
    } catch (error) {
        console.error('Erro na sincronização de notificações:', error);
    }
}

// Listener para mensagens do cliente - MELHORADO
self.addEventListener('message', function(event) {
    const data = event.data;
    
    if (data.action === 'skipWaiting') {
        self.skipWaiting();
    }
    
    if (data.action === 'checkNotifications') {
        // Força verificação de novas notificações
        event.waitUntil(syncNotifications());
    }
    
    if (data.action === 'sendManualNotification') {
        // Envia notificação manual via Service Worker
        const notificationData = data.notificationData;
        
        const options = {
            body: notificationData.message,
            icon: '/plugins/sinodenotificacao/icon.png',
            badge: '/plugins/sinodenotificacao/icon.png',
            tag: 'glpi-manual-' + Date.now(),
            requireInteraction: notificationData.priority === 'urgent' || notificationData.priority === 'high',
            vibrate: getVibratePattern(notificationData.priority),
            timestamp: Date.now(),
            lang: 'pt-BR',
            data: {
                url: '/front/central.php',
                isManual: true,
                priority: notificationData.priority,
                itemType: 'manual'
            }
        };
        
        const priorityEmojis = {
            'urgent': '🚨',
            'high': '⚠️', 
            'normal': '📢',
            'low': 'ℹ️'
        };
        
        const title = `${priorityEmojis[notificationData.priority] || '📢'} GLPI - ${notificationData.title}`;
        
        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    }
});

// Função auxiliar para padrões de vibração
function getVibratePattern(priority) {
    switch (priority) {
        case 'urgent': return [300, 100, 300, 100, 300, 100, 300];
        case 'high': return [250, 100, 250, 100, 250];
        case 'normal': return [200, 100, 200];
        case 'low': return [100, 50, 100];
        default: return [200, 100, 200];
    }
}

// Cache para recursos do plugin (opcional)
self.addEventListener('fetch', function(event) {
    // Cache apenas recursos do plugin sino de notificação
    if (event.request.url.includes('/plugins/sinodenotificacao/')) {
        event.respondWith(
            caches.match(event.request).then(function(response) {
                // Retorna do cache se disponível, senão busca da rede
                return response || fetch(event.request);
            })
        );
    }
});

// Listener para notificações push de sistemas externos (opcional)
self.addEventListener('pushsubscriptionchange', function(event) {
    //console.log('Push subscription changed');
    
    event.waitUntil(
        // Aqui você pode implementar lógica para reregistrar a subscription
        // se o seu sistema usar push notifications de servidor externo
        Promise.resolve()
    );
});

//console.log('🔔 Service Worker do Sino de Notificação carregado - versão completa com notificações manuais');