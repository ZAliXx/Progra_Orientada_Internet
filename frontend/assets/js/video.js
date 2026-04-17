class VideoCall {
    constructor(userId, targetUserId) {
        this.userId = userId;
        this.targetUserId = targetUserId;
        this.localStream = null;
        this.peerConnection = null;
        this.ws = null;
        
        this.configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' }
            ]
        };
        
        this.initWebSocket();
    }

    async initWebSocket() {
        this.ws = new WebSocket('ws://localhost:8080');
        
        this.ws.onmessage = async (event) => {
            const data = JSON.parse(event.data);
            
            if (data.type === 'video_call') {
                if (data.signal.type === 'offer') {
                    await this.handleOffer(data.signal);
                } else if (data.signal.type === 'answer') {
                    await this.handleAnswer(data.signal);
                } else if (data.signal.candidate) {
                    await this.handleIceCandidate(data.signal);
                }
            }
        };
    }

    async startCall() {
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true
            });
            
            document.getElementById('local-video').srcObject = this.localStream;
            
            this.peerConnection = new RTCPeerConnection(this.configuration);
            
            this.localStream.getTracks().forEach(track => {
                this.peerConnection.addTrack(track, this.localStream);
            });
            
            this.peerConnection.onicecandidate = (event) => {
                if (event.candidate) {
                    this.ws.send(JSON.stringify({
                        type: 'video_call',
                        targetUserId: this.targetUserId,
                        signal: { candidate: event.candidate }
                    }));
                }
            };
            
            this.peerConnection.ontrack = (event) => {
                document.getElementById('remote-video').srcObject = event.streams[0];
            };
            
            const offer = await this.peerConnection.createOffer();
            await this.peerConnection.setLocalDescription(offer);
            
            this.ws.send(JSON.stringify({
                type: 'video_call',
                targetUserId: this.targetUserId,
                signal: offer
            }));
            
        } catch (error) {
            console.error('Error al iniciar llamada:', error);
        }
    }

    async handleOffer(offer) {
        this.localStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: true
        });
        
        document.getElementById('local-video').srcObject = this.localStream;
        
        this.peerConnection = new RTCPeerConnection(this.configuration);
        
        this.localStream.getTracks().forEach(track => {
            this.peerConnection.addTrack(track, this.localStream);
        });
        
        this.peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                this.ws.send(JSON.stringify({
                    type: 'video_call',
                    targetUserId: this.targetUserId,
                    signal: { candidate: event.candidate }
                }));
            }
        };
        
        this.peerConnection.ontrack = (event) => {
            document.getElementById('remote-video').srcObject = event.streams[0];
        };
        
        await this.peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
        const answer = await this.peerConnection.createAnswer();
        await this.peerConnection.setLocalDescription(answer);
        
        this.ws.send(JSON.stringify({
            type: 'video_call',
            targetUserId: this.targetUserId,
            signal: answer
        }));
    }

    async handleAnswer(answer) {
        await this.peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
    }

    async handleIceCandidate(candidate) {
        await this.peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
    }

    endCall() {
        if (this.peerConnection) {
            this.peerConnection.close();
        }
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => track.stop());
        }
    }
}