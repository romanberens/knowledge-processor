from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class AttachmentIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    attachment_id: str | None = Field(default=None, min_length=4, max_length=64)
    file_name: str = Field(min_length=1, max_length=255)
    mime_type: str | None = Field(default=None, max_length=128)
    size_bytes: int | None = Field(default=None, ge=0)
    storage_ref: str | None = Field(default=None, max_length=2048)
    metadata: dict[str, Any] = Field(default_factory=dict)


class ThreadCreateIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    thread_id: str | None = Field(default=None, min_length=4, max_length=64)
    title: str = Field(min_length=1, max_length=255)
    project_id: str | None = Field(default=None, max_length=128)
    assistant_id: str | None = Field(default=None, max_length=128)
    status: str = Field(default="active", min_length=2, max_length=32)
    metadata: dict[str, Any] = Field(default_factory=dict)


class MessageCreateIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    message_id: str | None = Field(default=None, min_length=4, max_length=64)
    parent_message_id: str | None = Field(default=None, min_length=4, max_length=64)
    role: Literal["system", "user", "assistant", "tool"]
    content_text: str = Field(min_length=1)
    mode: str = Field(default="default", min_length=2, max_length=64)
    source: str = Field(default="local_ui", min_length=2, max_length=64)
    status: str = Field(default="accepted", min_length=2, max_length=32)
    tool_name: str | None = Field(default=None, max_length=128)
    metadata: dict[str, Any] = Field(default_factory=dict)
    attachments: list[AttachmentIn] = Field(default_factory=list)


class IntegrationEventIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    event_id: str | None = Field(default=None, min_length=4, max_length=64)
    event_type: str = Field(min_length=2, max_length=64)
    thread_id: str | None = Field(default=None, min_length=4, max_length=64)
    message_id: str | None = Field(default=None, min_length=4, max_length=64)
    source: str = Field(default="web_panel", min_length=2, max_length=64)
    payload: dict[str, Any] = Field(default_factory=dict)


class ThreadExchangeIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    prompt: str = Field(min_length=1)
    mode: str = Field(default="default", min_length=2, max_length=64)
    source: str = Field(default="web_panel", min_length=2, max_length=64)
    parent_message_id: str | None = Field(default=None, min_length=4, max_length=64)
    user_message_id: str | None = Field(default=None, min_length=4, max_length=64)
    assistant_message_id: str | None = Field(default=None, min_length=4, max_length=64)
    comparison_preference: Literal["first", "second"] = "first"
    metadata: dict[str, Any] = Field(default_factory=dict)
    assistant_metadata: dict[str, Any] = Field(default_factory=dict)


class ThreadHistorySyncIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    conversation_url: str | None = Field(default=None, max_length=2048)
    mode: str = Field(default="default", min_length=2, max_length=64)
    source: str = Field(default="chatgpt_ui_sync", min_length=2, max_length=64)


class SyncStartIn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    project_id: str | None = Field(default=None, max_length=128)
    assistant_id: str | None = Field(default=None, max_length=128)
    mode: str = Field(default="default", min_length=2, max_length=64)
    source: str = Field(default="chatgpt_ui_sync", min_length=2, max_length=64)
    mirror_delete_local: bool = True
    max_rounds: int = Field(default=4000, ge=8, le=20000)
    max_threads: int = Field(default=5000, ge=1, le=20000)
