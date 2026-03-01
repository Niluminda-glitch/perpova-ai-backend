# Agency Drop-in AI Search Widget (Laravel 11 & Llama 3)

A Proof-of-Work built specifically for Perpova Developers' **"Seamlessly Integrated AI"** initiative.

## The Concept

IT Dev Agencies build lots of WordPress and bespoke websites for clients. This system allows agencies to easily upsell an **"AI Chat Assistant"** to those clients.

## How It Works

1. The agency pastes a client's URL into the Laravel API.
2. Laravel scrapes, cleans, and vectorizes the text using **Hugging Face** models, storing it in a **PostgreSQL/pgvector** database.
3. The client drops the **React Chat Widget** onto their website, which allows end-users to ask questions using the **Llama 3 LLM** (strictly grounded in the client's website data via **Cosine Similarity** search).

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP / Laravel 11 |
| Database | Supabase (pgvector) |
| Embeddings | Hugging Face |
| LLM | Groq (Llama-3.3-70b) |
| Frontend | React / TypeScript |
| Styling | Tailwind v4 |