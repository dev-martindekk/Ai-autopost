-- add_openrouter.sql
-- Adds OpenRouter as an AI provider option
-- ============================================================================

INSERT IGNORE INTO `ai_settings`
    (provider_name, display_name, api_key, api_endpoint, default_model,
     available_models, temperature, max_tokens, is_primary, is_enabled)
VALUES (
    'openrouter',
    'OpenRouter',
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'anthropic/claude-sonnet-4-5',
    JSON_ARRAY(
        'anthropic/claude-sonnet-4-5',
        'anthropic/claude-3-5-sonnet',
        'anthropic/claude-3-haiku',
        'anthropic/claude-3-opus',
        'openai/gpt-4o',
        'openai/gpt-4o-mini',
        'openai/o1',
        'openai/o3-mini',
        'openai/gpt-4-turbo',
        'google/gemini-pro-1.5',
        'google/gemini-flash-1.5',
        'google/gemini-2.0-flash-001',
        'meta-llama/llama-3.1-70b-instruct',
        'meta-llama/llama-3.1-8b-instruct',
        'meta-llama/llama-3.3-70b-instruct',
        'deepseek/deepseek-chat',
        'deepseek/deepseek-r1',
        'mistralai/mistral-7b-instruct',
        'mistralai/mixtral-8x7b-instruct',
        'qwen/qwen-2.5-72b-instruct',
        'cohere/command-r-plus',
        'x-ai/grok-2-1212',
        'microsoft/phi-4'
    ),
    0.7,
    12000,
    0,
    1
);
