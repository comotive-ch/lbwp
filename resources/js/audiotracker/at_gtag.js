class AudioTracker {
    constructor(audioElement, trackingConfig = {}) {
        this.audio = audioElement;
        this.config = {
            sendInterval: 10, // Send listening time every X seconds
            progressMilestones: [25, 50, 75, 100],
            ...trackingConfig
        };
        
        // Tracking state
        this.listeningTime = 0;
        this.lastUpdateTime = null;
        this.milestonesSent = new Set();
        this.hasStarted = false;
        
        this.init();
    }
    
    init() {
        // Core events
        this.audio.addEventListener('play', () => this.handlePlay());
        this.audio.addEventListener('pause', () => this.handlePause());
        this.audio.addEventListener('ended', () => this.handleEnded());
        this.audio.addEventListener('seeked', () => this.handleSeeked());
        
        // Track listening time while playing
        this.audio.addEventListener('timeupdate', () => this.updateListeningTime());
        
        // Send remaining data before page unload
        window.addEventListener('beforeunload', () => this.handleBeforeUnload());
    }
    
    handlePlay() {
        this.lastUpdateTime = Date.now();
        
        const eventName = this.hasStarted ? 'audio_resume' : 'audio_play';
        this.hasStarted = true;
        
        gtag('event', eventName, {
            audio_title: this.getAudioTitle(),
            audio_duration: Math.round(this.audio.duration),
            current_time: Math.round(this.audio.currentTime)
        });
    }
    
    handlePause() {
        this.updateListeningTime();
        this.sendListeningTime();
        
        gtag('event', 'audio_pause', {
            audio_title: this.getAudioTitle(),
            current_time: Math.round(this.audio.currentTime),
            listening_time: Math.round(this.listeningTime)
        });
        
        this.lastUpdateTime = null;
    }
    
    handleEnded() {
        this.updateListeningTime();
        this.sendListeningTime();
        
        gtag('event', 'audio_complete', {
            audio_title: this.getAudioTitle(),
            total_listening_time: Math.round(this.listeningTime),
            completion_rate: 100
        });
        
        this.lastUpdateTime = null;
    }
    
    handleSeeked() {
        // Only track if user actively seeks (not programmatic)
        if (!this.audio.paused) {
            gtag('event', 'audio_seek', {
                audio_title: this.getAudioTitle(),
                seek_to: Math.round(this.audio.currentTime)
            });
        }
    }
    
    updateListeningTime() {
        if (!this.lastUpdateTime || this.audio.paused) return;
        
        const now = Date.now();
        const elapsed = (now - this.lastUpdateTime) / 1000;
        
        // Prevent counting when tab is inactive (optional)
        if (elapsed < 2) { // Max 2 seconds between updates
            this.listeningTime += elapsed;
        }
        
        this.lastUpdateTime = now;
        
        // Check milestones
        this.checkProgressMilestones();
        
        // Send interval update
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
                
                gtag('event', 'audio_progress', {
                    audio_title: this.getAudioTitle(),
                    progress_percent: milestone,
                    listening_time: Math.round(this.listeningTime)
                });
            }
        });
    }
    
    sendListeningTime() {
        if (this.listeningTime === 0) return;
        
        gtag('event', 'audio_listening_time', {
            audio_title: this.getAudioTitle(),
            listening_seconds: Math.round(this.listeningTime),
            engagement_rate: this.calculateEngagementRate()
        });
    }
    
    handleBeforeUnload() {
        if (this.listeningTime > 0) {
            this.updateListeningTime();
            
            // Use sendBeacon for reliable sending on page unload
            if (navigator.sendBeacon && typeof gtag !== 'undefined') {
                gtag('event', 'audio_session_end', {
                    audio_title: this.getAudioTitle(),
                    total_listening_time: Math.round(this.listeningTime),
                    engagement_rate: this.calculateEngagementRate(),
                    transport_type: 'beacon'
                });
            }
        }
    }
    
    calculateEngagementRate() {
        if (!this.audio.duration) return 0;
        return Math.round((this.listeningTime / this.audio.duration) * 100);
    }
    
    getAudioTitle() {
        // Try to get meaningful title
        return this.audio.dataset.title || 
               this.audio.title || 
               this.audio.src.split('/').pop() || 
               'unknown';
    }
}

// Usage
const audioPlayer = document.querySelector('audio');
const tracker = new AudioTracker(audioPlayer, {
    sendInterval: 15, // Optional: custom interval
    progressMilestones: [25, 50, 75, 100] // Optional: custom milestones
});
