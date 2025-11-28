#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Offline Voice Assistant Chatbot - Flask Server
Provides REST API for voice and text-based Q&A using local models
"""

import os
import sys
import json
import uuid
import csv
import io
from pathlib import Path

# Fix encoding for Windows
if sys.platform.startswith('win'):
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

from flask import Flask, render_template, request, jsonify, send_from_directory
from werkzeug.utils import secure_filename
from pydub import AudioSegment
import speech_recognition as sr
import pyttsx3
from rapidfuzz import fuzz

# Initialize Flask app
app = Flask(__name__)

# Configuration
UPLOAD_DIR = os.path.join(os.path.dirname(__file__), 'uploads')
AUDIO_OUT = os.path.join(os.path.dirname(__file__), 'static', 'tts')
DATA_DIR = os.path.join(os.path.dirname(__file__), 'data')
MODELS_DIR = os.path.join(os.path.dirname(__file__), 'models')

# Create directories if they don't exist
os.makedirs(UPLOAD_DIR, exist_ok=True)
os.makedirs(AUDIO_OUT, exist_ok=True)
os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs(MODELS_DIR, exist_ok=True)

app.config['UPLOAD_FOLDER'] = UPLOAD_DIR
app.config['MAX_CONTENT_LENGTH'] = 16 * 1024 * 1024  # 16MB max file size

# Initialize speech recognition and TTS
recognizer = sr.Recognizer()
tts_engine = pyttsx3.init()
tts_engine.setProperty('rate', 150)

# Load Q&A pairs from CSV
qa_pairs = []

def load_qa_data():
    """Load Q&A data from CSV file"""
    global qa_pairs
    qa_pairs = []
    
    # Check for CSV file in data folder
    csv_path = os.path.join(DATA_DIR, 'qa_(CSV).csv')
    
    if os.path.exists(csv_path):
        try:
            with open(csv_path, 'r', encoding='utf-8', errors='ignore') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    if row:
                        # Handle different column names
                        question = row.get('Question') or row.get('question') or row.get('Q') or ''
                        answer = row.get('Answer') or row.get('answer') or row.get('A') or ''
                        
                        if question and answer:
                            qa_pairs.append({
                                'question': question.strip(),
                                'answer': answer.strip()
                            })
            
            print(f" Loaded {len(qa_pairs)} Q&A pairs from CSV")
            
            # If CSV is empty or missing, use sample data
            if not qa_pairs:
                load_sample_data()
        except Exception as e:
            print(f"Error loading CSV: {e}")
            load_sample_data()
    else:
        print(f"CSV file not found at {csv_path}")
        load_sample_data()

def load_sample_data():
    """Load sample Q&A data for demonstration"""
    global qa_pairs
    
    sample_data = [
        {
            'question': 'Hello, how are you?',
            'answer': 'I am doing well, thank you for asking! How can I help you today?'
        },
        {
            'question': 'What is your name?',
            'answer': 'I am an offline voice assistant chatbot designed to help answer your questions.'
        },
        {
            'question': 'How can I use this chatbot?',
            'answer': 'You can either click the microphone button to speak your question or type it in the text box. I will respond with both text and voice.'
        },
        {
            'question': 'Tell me a joke',
            'answer': 'Why don'\''t scientists trust atoms? Because they make up everything!'
        },
        {
            'question': 'What is artificial intelligence?',
            'answer': 'Artificial Intelligence (AI) is the simulation of human intelligence processes by machines, especially computer systems. These processes include learning, reasoning, and self-correction.'
        },
        {
            'question': 'Can you help me?',
            'answer': 'Of course! I am here to help. Please ask me any questions you have.'
        }
    ]
    
    qa_pairs = sample_data
    print(f" Loaded {len(qa_pairs)} sample Q&A pairs")

def find_best_answer(question, qa_data):
    """Find the best matching answer for a question"""
    if not qa_data:
        return "I don'\''t have any data to answer your question. Please try again."
    
    if not question.strip():
        return "Could you please rephrase your question?"
    
    best_match = None
    best_score = 0
    threshold = 40
    
    for qa in qa_data:
        score = fuzz.token_set_ratio(question.lower(), qa['question'].lower())
        
        if score > best_score:
            best_score = score
            best_match = qa
    
    if best_match and best_score >= threshold:
        return best_match['answer']
    else:
        return f"I'\''m not sure how to answer that question about '\''{question}'\''. Could you rephrase it or ask something else?"

def transcribe_wav(wav_path):
    """Transcribe WAV file to text using speech recognition"""
    try:
        with sr.AudioFile(wav_path) as source:
            audio = recognizer.record(source)
        
        text = recognizer.recognize_google(audio)
        return text
    
    except sr.UnknownValueError:
        return "Could not understand the audio"
    except sr.RequestError as e:
        return f"Error: {str(e)}"
    except Exception as e:
        print(f"Transcription error: {e}")
        return "Error transcribing audio"

def synthesize_tts(text, output_dir):
    """Synthesize text to speech and save as MP3"""
    try:
        filename = f"tts_{uuid.uuid4().hex}.mp3"
        output_path = os.path.join(output_dir, filename)
        
        tts_engine.save_to_file(text, output_path)
        tts_engine.runAndWait()
        
        if os.path.exists(output_path):
            return filename
        
        silent = AudioSegment.silent(duration=500)
        silent.export(output_path, format='mp3')
        return filename
    
    except Exception as e:
        print(f"TTS error: {e}")
        filename = f"tts_{uuid.uuid4().hex}.mp3"
        output_path = os.path.join(output_dir, filename)
        silent = AudioSegment.silent(duration=500)
        silent.export(output_path, format='mp3')
        return filename

@app.route('"'"'/'"'"')
def index():
    """Serve the main HTML page"""
    return render_template('index.html')

@app.route('"'"'/api/voice'"'"', methods=['POST'])
def api_voice():
    """Handle voice input"""
    try:
        if '"'"'audio'"'"' not in request.files:
            return jsonify({'error': 'no audio file'}), 400
        
        f = request.files['"'"'audio'"'"']
        name = secure_filename(f.filename)
        in_path = os.path.join(UPLOAD_DIR, f"{uuid.uuid4().hex}_{name}")
        f.save(in_path)
        
        wav_path = in_path
        try:
            if not in_path.lower().endswith('.wav'):
                audio = AudioSegment.from_file(in_path)
                wav_path = in_path + '.wav'
                audio = audio.set_frame_rate(16000).set_channels(1)
                audio.export(wav_path, format='wav')
        except Exception as e:
            print(f'Audio conversion failed: {e}')
            return jsonify({'error': 'audio conversion failed'}), 400
        
        text = transcribe_wav(wav_path)
        answer = find_best_answer(text, qa_pairs)
        tts_file = synthesize_tts(answer, AUDIO_OUT)
        
        try:
            os.remove(in_path)
            if os.path.exists(wav_path):
                os.remove(wav_path)
        except:
            pass
        
        return jsonify({
            'transcript': text,
            'reply': answer,
            'tts': f'/static/tts/{tts_file}'
        })
    
    except Exception as e:
        print(f"Voice API error: {e}")
        return jsonify({'error': str(e)}), 500

@app.route('"'"'/api/text'"'"', methods=['POST'])
def api_text():
    """Handle text input"""
    try:
        data = request.json or {}
        text = data.get('text', '').strip()
        
        if not text:
            return jsonify({'error': 'no text provided'}), 400
        
        answer = find_best_answer(text, qa_pairs)
        tts_file = synthesize_tts(answer, AUDIO_OUT)
        
        return jsonify({
            'reply': answer,
            'tts': f'/static/tts/{tts_file}'
        })
    
    except Exception as e:
        print(f"Text API error: {e}")
        return jsonify({'error': str(e)}), 500

@app.route('"'"'/api/info'"'"', methods=['GET'])
def api_info():
    """Get system information"""
    return jsonify({
        'status': 'running',
        'qa_pairs_loaded': len(qa_pairs),
        'version': '1.0.0'
    })

@app.route('"'"'/static/tts/<path:filename>'"'"')
def send_tts(filename):
    """Serve TTS audio files"""
    return send_from_directory(AUDIO_OUT, filename)

if __name__ == '__main__':
    print("\n" + "="*60)
    print(" Offline Voice Assistant Chatbot - Flask Server")
    print("="*60)
    print()
    
    print("Loading Q&A data...")
    load_qa_data()
    print()
    
    print(f" Upload directory: {UPLOAD_DIR}")
    print(f" Audio output directory: {AUDIO_OUT}")
    print(f" Data directory: {DATA_DIR}")
    print()
    
    print("Starting Flask server...")
    print(" Access the chatbot at: http://localhost:5000")
    print()
    print("="*60)
    print()
    
    app.run(host='0.0.0.0', port=5000, debug=True)
