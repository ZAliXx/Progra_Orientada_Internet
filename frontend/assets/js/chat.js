class ChatManager {
    constructor(userId, groupId) {
        this.userId = userId;
        this.groupId = groupId;
        this.ws = null;
        this.initWebSocket();
        this.initEventListeners();
    }

    initWebSocket() {
        this.ws = new WebSocket('ws://localhost:8080');
        
        this.ws.onopen = () => {
            console.log('Conectado al servidor');
            this.joinGroup();
        };

        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleMessage(data);
        };

        this.ws.onclose = () => {
            console.log('Desconectado del servidor');
        };
    }

    joinGroup() {
        this.ws.send(JSON.stringify({
            type: 'join',
            userId: this.userId,
            groups: [this.groupId]
        }));
    }

    sendMessage(message, file = null) {
        const messageData = {
            type: 'chat',
            groupId: this.groupId,
            user: this.userId,
            message: message,
            timestamp: new Date(),
            file: file
        };

        this.ws.send(JSON.stringify(messageData));
        this.displayMessage(messageData, 'sent');
    }

    sendFile(file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            this.sendMessage('', {
                name: file.name,
                type: file.type,
                data: e.target.result
            });
        };
        reader.readAsDataURL(file);
    }

    sendTyping(isTyping) {
        this.ws.send(JSON.stringify({
            type: 'typing',
            groupId: this.groupId,
            user: this.userId,
            isTyping: isTyping
        }));
    }

    handleMessage(data) {
        switch(data.type) {
            case 'new_message':
                this.displayMessage(data, 'received');
                break;
            case 'typing':
                this.showTypingIndicator(data.user, data.isTyping);
                break;
            case 'task_updated':
                this.updateTaskUI(data.task);
                break;
        }
    }

    displayMessage(data, type) {
        const messagesContainer = document.getElementById('messages');
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}`;
        
        if (data.file) {
            // Mostrar archivo adjunto
            if (data.file.type.startsWith('image/')) {
                messageDiv.innerHTML = `
                    <img src="${data.file.data}" alt="${data.file.name}">
                `;
            } else {
                messageDiv.innerHTML = `
                    <a href="${data.file.data}" download="${data.file.name}">
                        📎 ${data.file.name}
                    </a>
                `;
            }
        } else {
            messageDiv.textContent = data.message;
        }
        
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    initEventListeners() {
        const messageInput = document.getElementById('message-input');
        const sendButton = document.getElementById('send-button');
        const fileInput = document.getElementById('file-input');

        if (messageInput) {
            messageInput.addEventListener('input', () => {
                this.sendTyping(messageInput.value.length > 0);
            });

            messageInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.sendMessage(messageInput.value);
                    messageInput.value = '';
                }
            });
        }

        if (sendButton) {
            sendButton.addEventListener('click', () => {
                if (messageInput.value.trim()) {
                    this.sendMessage(messageInput.value);
                    messageInput.value = '';
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                Array.from(e.target.files).forEach(file => {
                    this.sendFile(file);
                });
            });
        }
    }

    showTypingIndicator(user, isTyping) {
        const indicator = document.getElementById('typing-indicator');
        if (isTyping) {
            indicator.textContent = `Usuario ${user} está escribiendo...`;
            indicator.style.display = 'block';
        } else {
            indicator.style.display = 'none';
        }
    }
}

// Inicializar
const chat = new ChatManager(1, 1);