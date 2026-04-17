class TaskManager {
    constructor(userId) {
        this.userId = userId;
        this.tasks = [];
        this.loadTasks();
    }

    async loadTasks() {
        const response = await fetch(`/api/tasks.php?user_id=${this.userId}`);
        this.tasks = await response.json();
        this.renderTasks();
    }

    async createTask(taskData) {
        const response = await fetch('/api/tasks.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                ...taskData,
                usuario_id: this.userId
            })
        });
        
        const newTask = await response.json();
        this.tasks.push(newTask);
        this.renderTasks();
    }

    async toggleTask(taskId) {
        const task = this.tasks.find(t => t.id === taskId);
        task.completada = !task.completada;
        
        await fetch(`/api/tasks.php?id=${taskId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ completada: task.completada })
        });
        
        this.renderTasks();
        
        // Notificar por WebSocket
        this.notifyTaskUpdate(task);
    }

    unlockSpecialTasks(boostLevel) {
        const specialTasks = [
            { titulo: 'Tarea Especial Nivel 1', descripcion: 'Completa esta tarea para ganar 50 monedas', nivel: 1 },
            { titulo: 'Tarea Especial Nivel 2', descripcion: 'Completa esta tarea para ganar 100 monedas', nivel: 2 },
            { titulo: 'Tarea Especial Nivel 3', descripcion: 'Completa esta tarea para ganar 200 monedas', nivel: 3 }
        ];
        
        specialTasks
            .filter(task => task.nivel <= boostLevel)
            .forEach(task => this.createTask(task));
    }

    renderTasks() {
        const container = document.getElementById('tasks-container');
        if (!container) return;
        
        container.innerHTML = '';
        
        this.tasks.forEach(task => {
            const taskElement = document.createElement('div');
            taskElement.className = `task-item ${task.completada ? 'completed' : ''}`;
            taskElement.innerHTML = `
                <input type="checkbox" 
                       ${task.completada ? 'checked' : ''} 
                       onchange="taskManager.toggleTask(${task.id})">
                <div class="task-content">
                    <h4>${task.titulo}</h4>
                    <p>${task.descripcion}</p>
                </div>
            `;
            container.appendChild(taskElement);
        });
    }

    notifyTaskUpdate(task) {
        if (this.ws) {
            this.ws.send(JSON.stringify({
                type: 'task_update',
                groupId: task.grupo_id,
                task: task
            }));
        }
    }
}

// Inicializar task manager
const taskManager = new TaskManager(1);