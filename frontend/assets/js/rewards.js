class RewardSystem {
    constructor(userId) {
        this.userId = userId;
        this.monedas = 0;
        this.boostLevel = 1;
        this.boostMultiplier = 1;
        this.timeAccumulator = 0;
        this.isActive = true;
        
        this.startTimer();
    }

    startTimer() {
        setInterval(() => {
            if (this.isActive) {
                this.timeAccumulator += 1;
                
                // Ganar 1 moneda cada 5 segundos (multiplicado por boost)
                if (this.timeAccumulator >= 5) {
                    const ganancia = Math.floor(this.timeAccumulator / 5) * this.boostMultiplier;
                    this.addCoins(ganancia);
                    this.timeAccumulator = this.timeAccumulator % 5;
                    
                    // Verificar si llegó a 100 monedas
                    if (this.monedas >= 100) {
                        this.showRewardOptions();
                    }
                }
            }
        }, 1000);
    }

    addCoins(cantidad) {
        this.monedas += cantidad;
        this.updateUI();
        
        // Actualizar en el servidor
        this.updateServerCoins();
    }

    useCoins(cantidad) {
        if (this.monedas >= cantidad) {
            this.monedas -= cantidad;
            this.updateUI();
            return true;
        }
        return false;
    }

    buyBoost() {
        if (this.useCoins(100)) {
            this.boostLevel++;
            this.boostMultiplier = this.boostLevel; // Cada nivel multiplica por el nivel
            this.updateUI();
            
            // Desbloquear tareas especiales
            this.unlockSpecialTasks();
            
            return true;
        }
        return false;
    }

    unlockStore() {
        if (this.useCoins(100)) {
            // Desbloquear tienda de fondos y stickers
            document.getElementById('store-section').style.display = 'block';
            return true;
        }
        return false;
    }

    showRewardOptions() {
        const modal = document.getElementById('reward-modal');
        modal.style.display = 'block';
        
        document.getElementById('boost-option').onclick = () => {
            if (this.buyBoost()) {
                modal.style.display = 'none';
            }
        };
        
        document.getElementById('store-option').onclick = () => {
            if (this.unlockStore()) {
                modal.style.display = 'none';
            }
        };
    }

    unlockSpecialTasks() {
        // Desbloquear tareas especiales según nivel de boost
        const taskManager = new TaskManager(this.userId);
        taskManager.unlockSpecialTasks(this.boostLevel);
    }

    updateUI() {
        document.getElementById('monedas-count').textContent = this.monedas;
        document.getElementById('boost-level').textContent = this.boostLevel;
    }

    updateServerCoins() {
        fetch('/api/update-coins.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                userId: this.userId,
                monedas: this.monedas,
                boostLevel: this.boostLevel
            })
        });
    }
}

// Inicializar sistema de recompensas
const rewardSystem = new RewardSystem(1);