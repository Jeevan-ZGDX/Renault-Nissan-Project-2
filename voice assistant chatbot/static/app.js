const startBtn = document.getElementById('startBtn')


function appendMessage(text, who='bot', ttsUrl=null){
const div = document.createElement('div')
div.className = 'msg ' + (who==='user' ? 'user' : 'bot')
div.innerText = text
chat.appendChild(div)
chat.scrollTop = chat.scrollHeight
if(ttsUrl){
const a = document.createElement('audio')
a.controls = true
a.src = ttsUrl
chat.appendChild(a)
}
}


startBtn.onclick = async ()=>{
if(!navigator.mediaDevices) return alert('MediaDevices not supported')
const stream = await navigator.mediaDevices.getUserMedia({audio:true})
mediaRecorder = new MediaRecorder(stream)
audioChunks = []
mediaRecorder.ondataavailable = e => audioChunks.push(e.data)
mediaRecorder.onstop = async ()=>{
const blob = new Blob(audioChunks, {type:'audio/webm'})
const fd = new FormData()
fd.append('audio', blob, 'speech.webm')
appendMessage('Recording sent...', 'user')
const res = await fetch('/api/voice', {method:'POST', body:fd})
const j = await res.json()
appendMessage('You said: ' + (j.transcript || '[no text]'), 'user')
appendMessage(j.reply || '[no reply]', 'bot', j.tts)
}
mediaRecorder.start()
startBtn.disabled = true
stopBtn.disabled = false
}


stopBtn.onclick = ()=>{
if(mediaRecorder) mediaRecorder.stop()
startBtn.disabled = false
stopBtn.disabled = true
}


sendText.onclick = async ()=>{
const text = textInput.value.trim()
if(!text) return
appendMessage(text, 'user')
const res = await fetch('/api/text', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({text})})
const j = await res.json()
appendMessage(j.reply || '[no reply]', 'bot', j.tts)
textInput.value = ''
}


// allow enter key
textInput.addEventListener('keydown', (e)=>{if(e.key==='Enter'){sendText.click();e.preventDefault()}})