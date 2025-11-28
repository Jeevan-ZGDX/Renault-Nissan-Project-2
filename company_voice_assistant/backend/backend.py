# backend/backend.py

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional

app = FastAPI()

# CORS so that frontend (index.html) can call this from browser
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],    # in production, restrict this
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

class ChatRequest(BaseModel):
    message: str
    session_id: Optional[str] = None
    user_type: Optional[str] = "customer"  # "customer" or "employee"

class ChatResponse(BaseModel):
    reply: str
    end_session: bool = False


def generate_reply(message: str, user_type: Optional[str] = "customer") -> ChatResponse:
    lower = message.lower()

    # Stop keywords
    if any(x in lower for x in ["stop", "bye", "thank you", "thanks, bye"]):
        return ChatResponse(
            reply="Okay, ending our voice session now. If you need anything else, just start me again.",
            end_session=True,
        )

    prefix = "[Internal assistant] " if user_type == "employee" else "[Customer assistant] "
    reply = prefix + f"You said: {message}"

    return ChatResponse(reply=reply, end_session=False)


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest):
    return generate_reply(req.message, req.user_type)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
