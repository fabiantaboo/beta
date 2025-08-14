# 🔥 QDRANT INFERENCE API SETUP - DEBUG GUIDE

## Das Problem
```
❌ Qdrant storage FAILED: Service internal error: InferenceService is not initialized. 
Please check if it was properly configured and initialized during startup.
```

## Root Cause
Du verwendest wahrscheinlich die **Standard Qdrant Cluster API** statt der **Inference API**.

## ✅ LÖSUNG: Schritt-für-Schritt Setup

### 1. Qdrant Cloud Dashboard
- Gehe zu: https://cloud.qdrant.io
- Login in dein Account
- Öffne deinen Cluster

### 2. Inference API aktivieren
- In deinem Cluster → **Settings**
- Suche nach **"Inference API"** oder **"AI/ML Services"**  
- **AKTIVIERE** die Inference API
- Warte bis der Service "Running" ist

### 3. Inference API Key generieren
- **NICHT** den normalen Cluster API Key verwenden!
- Gehe zu **API Keys** → **Create new key**
- Wähle **"Inference API"** als Typ
- Generiere den Key und kopiere ihn

### 4. URLs konfigurieren
```php
// ✅ RICHTIG (Inference API):
define('QDRANT_URL', 'https://your-cluster-id.us-east.aws.cloud.qdrant.io');  // KEIN :6333
define('QDRANT_API_KEY', 'qir_abc123xyz...'); // Inference API Key (beginnt mit 'qir_')

// ❌ FALSCH (Cluster API):
define('QDRANT_URL', 'https://your-cluster-id.us-east.aws.cloud.qdrant.io:6333');
define('QDRANT_API_KEY', 'qat_def456...'); // Cluster API Key (beginnt mit 'qat_')
```

### 5. Test
- Öffne: `/admin/memory-setup`
- Klicke "Test Connection" 
- Sollte jetzt ✅ funktionieren

## 🔍 Debugging-Tipps

### API Key Unterschiede:
- **Cluster API Key** (`qat_...`): Für Datenbank-Operationen (Collections, Points)
- **Inference API Key** (`qir_...`): Für Embedding-Generierung (Text → Vektor)

### URL Unterschiede:
- **Cluster API**: `https://cluster.qdrant.io:6333/collections/...` 
- **Inference API**: `https://cluster.qdrant.io/collections/.../points?wait=true`

### Modelle 2025:
- `sentence-transformers/all-MiniLM-L6-v2` (384d) - Schnell
- `mixedbread-ai/mxbai-embed-large-v1` (1024d) - Beste Qualität
- `bm25` - Kostenlos unbegrenzt

## 🚨 Häufige Fehler
1. **Port :6333 verwendet** → Inference API braucht KEINEN Port
2. **Falscher API Key Typ** → Cluster Key ≠ Inference Key  
3. **Inference API nicht aktiviert** → Muss im Dashboard eingeschaltet werden
4. **Alte 2024 API Syntax** → Wir verwenden 2025 Format mit `vector.text + model`

## ✅ Erfolg erkennen
Wenn es funktioniert, siehst du im Debug Terminal:
```
📡 Attempting to store memory in Qdrant...
✅ Qdrant storage result: {"operation_id":123,"status":"ok"}
💾 Storing memory metadata in MySQL...
✅ MySQL storage completed successfully
🎉 Memory storage SUCCESSFUL
```