const WebSocket = require('ws');
const http = require('http');
const server = http.createServer();
const wss = new WebSocket.Server({ server });

// Almacenar conexiones activas
const clients = new Map();
const groups = new Map();

wss.on('connection', (ws) => {
    console.log('Nuevo cliente conectado');
    
    ws.on('message', (message) => {
        const data = JSON.parse(message);
        
        switch(data.type) {
            case 'join':
                handleJoin(ws, data);
                break;
            case 'chat':
                handleChat(ws, data);
                break;
            case 'video':
                handleVideo(ws, data);
                break;
            case 'typing':
                handleTyping(ws, data);
                break;
            case 'file':
                handleFile(ws, data);
                break;
            case 'task_update':
                handleTaskUpdate(ws, data);
                break;
        }
    });
    
    ws.on('close', () => {
        handleDisconnect(ws);
    });
});

function handleJoin(ws, data) {
    clients.set(data.userId, ws);
    ws.userId = data.userId;
    
    // Unir a grupos
    if (data.groups) {
        data.groups.forEach(groupId => {
            if (!groups.has(groupId)) {
                groups.set(groupId, new Set());
            }
            groups.get(groupId).add(ws);
        });
    }
}

function handleChat(ws, data) {
    const groupId = data.groupId;
    const message = {
        type: 'new_message',
        user: data.user,
        message: data.message,
        timestamp: new Date(),
        file: data.file
    };
    
    // Enviar a todos en el grupo
    if (groups.has(groupId)) {
        groups.get(groupId).forEach(client => {
            if (client.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify(message));
            }
        });
    }
}

function handleVideo(ws, data) {
    const targetUser = data.targetUserId;
    const targetWs = clients.get(targetUser);
    
    if (targetWs && targetWs.readyState === WebSocket.OPEN) {
        targetWs.send(JSON.stringify({
            type: 'video_call',
            from: ws.userId,
            signal: data.signal
        }));
    }
}

function handleTyping(ws, data) {
    if (groups.has(data.groupId)) {
        groups.get(data.groupId).forEach(client => {
            if (client !== ws && client.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify({
                    type: 'typing',
                    user: data.user,
                    isTyping: data.isTyping
                }));
            }
        });
    }
}

function handleFile(ws, data) {
    // Aquí procesarías la subida de archivos
    // Podrías guardar en el servidor y compartir la URL
}

function handleTaskUpdate(ws, data) {
    if (groups.has(data.groupId)) {
        groups.get(data.groupId).forEach(client => {
            if (client.readyState === WebSocket.OPEN) {
                client.send(JSON.stringify({
                    type: 'task_updated',
                    task: data.task
                }));
            }
        });
    }
}

function handleDisconnect(ws) {
    // Limpiar conexiones
    clients.delete(ws.userId);
    
    groups.forEach((group, groupId) => {
        group.delete(ws);
    });
}

server.listen(8080, () => {
    console.log('Servidor WebSocket corriendo en puerto 8080');
});