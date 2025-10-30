class MatomoAudioTracker {
    constructor(audioElement, trackingConfig = {}) {
        this.audio = audioElement;
        this.config = {
            sendInterval: 10,
            progressMilestones: [25, 50, 75, 100],
            category: 'Audio',
            ...trackingConfig
        };
        
        this.listeningTime = 0;
        this.lastUpdateTime = null;
        this.milestonesSent = new Set();
        this.hasStarted = false;
        
        this.init();
    }
    
    init() {
        this.audio.addEventListener('play', () => this.handlePlay());
        this.audio.addEventListener('pause', () => this.handlePause());
        this.audio.addEventListener('ended', () => this.handleEnded());
        this.audio.addEventListener('seeked', () => this.handleSeeked());
        this.audio.addEventListener('timeupdate', () => this.updateListeningTime());
        
        window.addEventListener('beforeunload', () => this.handleBeforeUnload());
    }
    
    handlePlay() {
        this.lastUpdateTime = Date.now();
        
        const action = this.hasStarted ? 'Resume' : 'Play';
        this.hasStarted = true;
        
        this.trackEvent(action, {
            name: this.getAudioTitle(),
            value: Math.round(this.audio.currentTime)
        });
    }
    
    handlePause() {
        this.updateListeningTime();
        
        this.trackEvent('Pause', {
            name: this.getAudioTitle(),
            value: Math.round(this.listeningTime)
        });
        
        this.lastUpdateTime = null;
    }
    
    handleEnded() {
        this.updateListeningTime();
        
        this.trackEvent('Complete', {
            name: this.getAudioTitle(),
            value: Math.round(this.listeningTime)
        });
        
        this.lastUpdateTime = null;
    }
    
    handleSeeked() {
        if (!this.audio.paused) {
            this.trackEvent('Seek', {
                name: this.getAudioTitle(),
                value: Math.round(this.audio.currentTime)
            });
        }
    }
    
    updateListeningTime() {
        if (!this.lastUpdateTime || this.audio.paused) return;
        
        const now = Date.now();
        const elapsed = (now - this.lastUpdateTime) / 1000;
        
        if (elapsed < 2) {
            this.listeningTime += elapsed;
        }
        
        this.lastUpdateTime = now;
        this.checkProgressMilestones();
        
        if (Math.floor(this.listeningTime) % this.config.sendInterval === 0) {
            this.sendListeningTime();
        }
    }
    
    checkProgressMilestones() {
        if (!this.audio.duration) return;
        
        const progress = (this.audio.currentTime / this.audio.duration) * 100;
        
        this.config.progressMilestones.forEach(milestone => {
            if (progress >= milestone && !this.milestonesSent.has(milestone)) {
                this.milestonesSent.add(milestone);
                
                this.trackEvent(`Progress ${milestone}%`, {
                    name: this.getAudioTitle(),
                    value: Math.round(this.listeningTime)
                });
            }
        });
    }
    
    sendListeningTime() {
        if (this.listeningTime === 0) return;
        
        this.trackEvent('Listening Time Update', {
            name: this.getAudioTitle(),
            value: Math.round(this.listeningTime)
        });
    }
    
    handleBeforeUnload() {
        if (this.listeningTime > 0) {
            this.updateListeningTime();
            
            // Matomo supports sendBeacon
            this.trackEvent('Session End', {
                name: this.getAudioTitle(),
                value: Math.round(this.listeningTime)
            });
        }
    }
    
    trackEvent(action, options = {}) {
        if (typeof _paq === 'undefined') {
            console.warn('Matomo _paq not found');
            return;
        }
        
        _paq.push([
            'trackEvent',
            this.config.category,
            action,
            options.name || '',
            options.value || 0
        ]);
    }
    
    calculateEngagementRate() {
        if (!this.audio.duration) return 0;
        return Math.round((this.listeningTime / this.audio.duration) * 100);
    }
    
    getAudioTitle() {
        return this.audio.dataset.title || 
               this.audio.title || 
               this.audio.src.split('/').pop() || 
               'unknown';
    }
}

// Usage
const audioPlayer = document.querySelector('audio');
const tracker = new MatomoAudioTracker(audioPlayer);