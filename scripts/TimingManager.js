/**
 * Universal Timing Manager
 * File: scripts/TimingManager.js
 * 
 * Handles item-level timing for both picking and receiving operations
 * Can be integrated into mobile picker and receiving interfaces
 */

class TimingManager {
    constructor(operationType = 'picking') {
        this.operationType = operationType; // 'picking' or 'receiving'
        this.activeTasks = new Map(); // taskId -> taskData
        this.timers = new Map(); // taskId -> intervalId
        this.apiBase = window.WMS_CONFIG?.apiBase || '/api';
        this.isInitialized = false;
        
        this.init();
    }

    init() {
        console.log(`🕐 TimingManager initialized for ${this.operationType}`);
        this.isInitialized = true;
        
        // Load any existing active tasks
        this.loadActiveTasks();
        
        // Set up auto-save for active tasks
        this.startAutoSave();
    }

    /**
     * Start timing for a specific item
     */
    async startTiming(taskData) {
        const {
            order_id,
            order_item_id,
            receiving_session_id,
            receiving_item_id,
            product_id,
            quantity_to_pick,
            quantity_to_receive,
            location_id
        } = taskData;

        try {
            console.log(`▶️ Starting ${this.operationType} timing for product ${product_id}`);
            
            const endpoint = `${this.apiBase}/timing/${this.operationType}_tasks.php?action=start`;
            const payload = this.operationType === 'picking' ? {
                order_id,
                order_item_id,
                product_id,
                quantity_to_pick,
                location_id
            } : {
                receiving_session_id,
                receiving_item_id,
                product_id,
                quantity_to_receive,
                location_id
            };

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            
            if (result.success) {
                const taskId = result.task_id;
                
                // Store task data
                this.activeTasks.set(taskId, {
                    ...taskData,
                    task_id: taskId,
                    start_time: result.start_time,
                    status: 'active'
                });

                // Start UI timer
                this.startUITimer(taskId);
                
                // Update UI
                this.updateTaskUI(taskId, 'started');
                
                console.log(`✅ ${this.operationType} timing started for task ${taskId}`);
                return { success: true, task_id: taskId };
                
            } else {
                throw new Error(result.error || 'Failed to start timing');
            }
            
        } catch (error) {
            console.error(`❌ Error starting ${this.operationType} timing:`, error);
            this.showError(`Failed to start timing: ${error.message}`);
            return { success: false, error: error.message };
        }
    }

    /**
     * Complete timing for a specific item
     */
    async completeTiming(taskId, completionData) {
        const {
            quantity_picked,
            quantity_received,
            notes,
            quality_check_notes,
            discrepancy_notes
        } = completionData;

        try {
            console.log(`⏹️ Completing ${this.operationType} timing for task ${taskId}`);
            
            const endpoint = `${this.apiBase}/timing/${this.operationType}_tasks.php?action=complete`;
            const payload = this.operationType === 'picking' ? {
                task_id: taskId,
                quantity_picked,
                notes
            } : {
                task_id: taskId,
                quantity_received,
                quality_check_notes,
                discrepancy_notes
            };

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            
            if (result.success) {
                // Stop UI timer
                this.stopUITimer(taskId);
                
                // Remove from active tasks
                this.activeTasks.delete(taskId);
                
                // Update UI
                this.updateTaskUI(taskId, 'completed', result.task);
                
                // Show completion message
                this.showSuccess(`${this.operationType} completed in ${result.duration_formatted}`);
                
                // Trigger dashboard refresh
                this.triggerDashboardRefresh();
                
                console.log(`✅ ${this.operationType} timing completed for task ${taskId}`);
                return { success: true, task: result.task };
                
            } else {
                throw new Error(result.error || 'Failed to complete timing');
            }
            
        } catch (error) {
            console.error(`❌ Error completing ${this.operationType} timing:`, error);
            this.showError(`Failed to complete timing: ${error.message}`);
            return { success: false, error: error.message };
        }
    }

    /**
     * Pause timing for a specific item
     */
    async pauseTiming(taskId) {
        try {
            console.log(`⏸️ Pausing ${this.operationType} timing for task ${taskId}`);
            
            const endpoint = `${this.apiBase}/timing/${this.operationType}_tasks.php?action=pause`;
            const response = await fetch(endpoint, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ task_id: taskId })
            });

            const result = await response.json();
            
            if (result.success) {
                // Stop UI timer
                this.stopUITimer(taskId);
                
                // Update task status
                const task = this.activeTasks.get(taskId);
                if (task) {
                    task.status = 'paused';
                    this.activeTasks.set(taskId, task);
                }
                
                // Update UI
                this.updateTaskUI(taskId, 'paused');
                
                console.log(`✅ ${this.operationType} timing paused for task ${taskId}`);
                return { success: true };
                
            } else {
                throw new Error(result.error || 'Failed to pause timing');
            }
            
        } catch (error) {
            console.error(`❌ Error pausing ${this.operationType} timing:`, error);
            this.showError(`Failed to pause timing: ${error.message}`);
            return { success: false, error: error.message };
        }
    }

    /**
     * Resume timing for a specific item
     */
    async resumeTiming(taskId) {
        try {
            console.log(`▶️ Resuming ${this.operationType} timing for task ${taskId}`);
            
            const endpoint = `${this.apiBase}/timing/${this.operationType}_tasks.php?action=resume`;
            const response = await fetch(endpoint, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ task_id: taskId })
            });

            const result = await response.json();
            
            if (result.success) {
                // Restart UI timer
                this.startUITimer(taskId);
                
                // Update task status
                const task = this.activeTasks.get(taskId);
                if (task) {
                    task.status = 'active';
                    this.activeTasks.set(taskId, task);
                }
                
                // Update UI
                this.updateTaskUI(taskId, 'resumed');
                
                console.log(`✅ ${this.operationType} timing resumed for task ${taskId}`);
                return { success: true };
                
            } else {
                throw new Error(result.error || 'Failed to resume timing');
            }
            
        } catch (error) {
            console.error(`❌ Error resuming ${this.operationType} timing:`, error);
            this.showError(`Failed to resume timing: ${error.message}`);
            return { success: false, error: error.message };
        }
    }

    /**
     * Load active tasks from server
     */
    async loadActiveTasks() {
        try {
            const endpoint = `${this.apiBase}/timing/${this.operationType}_tasks.php?action=active`;
            const response = await fetch(endpoint);
            const result = await response.json();
            
            if (result.success) {
                result.tasks.forEach(task => {
                    this.activeTasks.set(task.id, task);
                    this.startUITimer(task.id);
                });
                
                console.log(`📋 Loaded ${result.tasks.length} active ${this.operationType} tasks`);
            }
            
        } catch (error) {
            console.error(`❌ Error loading active tasks:`, error);
        }
    }

    /**
     * Start UI timer for a task
     */
    startUITimer(taskId) {
        // Clear existing timer
        this.stopUITimer(taskId);
        
        // Start new timer
        const timerId = setInterval(() => {
            this.updateElapsedTime(taskId);
        }, 1000);
        
        this.timers.set(taskId, timerId);
    }

    /**
     * Stop UI timer for a task
     */
    stopUITimer(taskId) {
        const timerId = this.timers.get(taskId);
        if (timerId) {
            clearInterval(timerId);
            this.timers.delete(taskId);
        }
    }

    /**
     * Update elapsed time display
     */
    updateElapsedTime(taskId) {
        const task = this.activeTasks.get(taskId);
        if (!task || task.status !== 'active') return;
        
        const startTime = new Date(task.start_time);
        const now = new Date();
        const elapsedSeconds = Math.floor((now - startTime) / 1000);
        
        // Update UI element
        const timerElement = document.getElementById(`timer-${taskId}`);
        if (timerElement) {
            timerElement.textContent = this.formatDuration(elapsedSeconds);
        }
    }

    /**
     * Update task UI based on status
     */
    updateTaskUI(taskId, status, taskData = null) {
        const taskElement = document.getElementById(`task-${taskId}`);
        if (!taskElement) return;
        
        // Update CSS classes
        taskElement.className = `task-item task-${status}`;
        
        // Update status indicator
        const statusElement = taskElement.querySelector('.task-status');
        if (statusElement) {
            statusElement.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        }
        
        // Update duration display if completed
        if (status === 'completed' && taskData) {
            const durationElement = taskElement.querySelector('.task-duration');
            if (durationElement) {
                durationElement.textContent = taskData.duration_formatted;
                durationElement.classList.add('completed');
            }
        }
    }

    /**
     * Get active tasks
     */
    getActiveTasks() {
        return Array.from(this.activeTasks.values());
    }

    /**
     * Get task by ID
     */
    getTask(taskId) {
        return this.activeTasks.get(taskId);
    }

    /**
     * Start auto-save for active tasks
     */
    startAutoSave() {
        // Save active tasks to localStorage every 30 seconds
        setInterval(() => {
            const tasks = Array.from(this.activeTasks.values());
            if (tasks.length > 0) {
                localStorage.setItem(`${this.operationType}_active_tasks`, JSON.stringify(tasks));
            }
        }, 30000);
    }

    /**
     * Format duration in seconds
     */
    formatDuration(seconds) {
        if (seconds < 60) {
            return `${seconds}s`;
        } else if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return `${minutes}m ${remainingSeconds}s`;
        } else {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const remainingSeconds = seconds % 60;
            return `${hours}h ${minutes}m ${remainingSeconds}s`;
        }
    }

    /**
     * Show success message
     */
    showSuccess(message) {
        if (window.showToast) {
            window.showToast(message, 'success');
        } else {
            console.log(`✅ ${message}`);
        }
    }

    /**
     * Show error message
     */
    showError(message) {
        if (window.showToast) {
            window.showToast(message, 'error');
        } else {
            console.error(`❌ ${message}`);
        }
    }

    /**
     * Trigger dashboard refresh
     */
    triggerDashboardRefresh() {
        if (window.refreshDashboardStats) {
            window.refreshDashboardStats();
        }
        
        // Trigger custom events
        const eventType = this.operationType === 'picking' ? 'orderProcessed' : 'receivingCompleted';
        document.dispatchEvent(new CustomEvent(eventType, { detail: { source: 'timing_manager' } }));
    }

    /**
     * Clean up when destroying
     */
    destroy() {
        // Stop all timers
        this.timers.forEach(timerId => clearInterval(timerId));
        this.timers.clear();
        
        // Clear active tasks
        this.activeTasks.clear();
        
        console.log(`🧹 TimingManager destroyed for ${this.operationType}`);
    }
}

// Export for use in other modules
window.TimingManager = TimingManager;