# Emotionssystem Dokumentation

## Emotionsmodell

### Unterstützte Emotionen

Das System verwendet ein 18-Emotionen-Modell basierend auf Plutchiks Rad der Emotionen und weiteren psychologischen Emotionstheorien:

#### Grundemotionen (Plutchik)
- **joy** (Freude) - Positive Emotion, Glück, Vergnügen
- **sadness** (Trauer) - Negative Emotion, Verlust, Melancholie  
- **fear** (Furcht) - Bedrohungsreaktion, Angst, Vorsicht
- **anger** (Zorn) - Ärger, Wut, Frustration über Hindernisse
- **surprise** (Überraschung) - Unerwartete Ereignisse, Staunen
- **disgust** (Ekel) - Ablehnung, Widerwille, Abneigung
- **trust** (Vertrauen) - Positive Bindung, Sicherheit
- **anticipation** (Erwartung) - Vorfreude, Spannung auf Zukünftiges

#### Erweiterte Emotionen
- **shame** (Scham) - Selbstverurteilung, Peinlichkeit
- **love** (Liebe) - Tiefe Zuneigung, Bindung
- **contempt** (Verachtung) - Geringschätzung, Überlegenheitsgefühl
- **loneliness** (Einsamkeit) - Isolation, sozialer Rückzug
- **pride** (Stolz) - Selbstwertgefühl, Erfolg
- **envy** (Neid) - Missgunst, Eifersucht
- **nostalgia** (Nostalgie) - Wehmut, Sehnsucht nach Vergangenem
- **gratitude** (Dankbarkeit) - Wertschätzung, Anerkennung
- **frustration** (Frustration) - Blockierung von Zielen, Ungeduld
- **boredom** (Langeweile) - Mangel an Stimulation, Interesse

### Wertebereiche

Alle Emotionen werden als Dezimalwerte zwischen 0.0 und 1.0 gespeichert:
- **0.0** - Emotion nicht vorhanden
- **0.1-0.3** - Schwache Ausprägung
- **0.4-0.6** - Moderate Ausprägung  
- **0.7-1.0** - Starke Ausprägung

Werte werden auf eine Nachkommastelle gerundet (0.1-Schritte).

## Systemarchitektur

### Datenbankstruktur

#### Dialog-Tabelle (dialogs)
Speichert den aktuellen emotionalen Zustand für AEI-Charaktere:

```sql
-- Emotionale Zustandsspalten in dialogs-Tabelle
aei_joy DECIMAL(3,2) DEFAULT 0.5,
aei_sadness DECIMAL(3,2) DEFAULT 0.5,
aei_fear DECIMAL(3,2) DEFAULT 0.5,
aei_anger DECIMAL(3,2) DEFAULT 0.5,
aei_surprise DECIMAL(3,2) DEFAULT 0.5,
aei_disgust DECIMAL(3,2) DEFAULT 0.5,
aei_trust DECIMAL(3,2) DEFAULT 0.5,
aei_anticipation DECIMAL(3,2) DEFAULT 0.5,
aei_shame DECIMAL(3,2) DEFAULT 0.5,
aei_love DECIMAL(3,2) DEFAULT 0.5,
aei_contempt DECIMAL(3,2) DEFAULT 0.5,
aei_loneliness DECIMAL(3,2) DEFAULT 0.5,
aei_pride DECIMAL(3,2) DEFAULT 0.5,
aei_envy DECIMAL(3,2) DEFAULT 0.5,
aei_nostalgia DECIMAL(3,2) DEFAULT 0.5,
aei_gratitude DECIMAL(3,2) DEFAULT 0.5,
aei_frustration DECIMAL(3,2) DEFAULT 0.5,
aei_boredom DECIMAL(3,2) DEFAULT 0.5
```

#### Dialog-Messages-Tabelle (dialog_messages)
Speichert emotionale Zustände für jede einzelne Nachricht:

```sql
-- Emotionale Daten pro Nachricht (NULL für User-Nachrichten)
aei_joy DECIMAL(3,2) NULL,
aei_sadness DECIMAL(3,2) NULL,
-- ... weitere 16 Emotionsspalten
```

### Klassenstruktur

#### Dialog-Klasse (`classes/Dialog.php`)

**Emotionskonstanten:**
```php
const EMOTIONS = [
    'joy', 'sadness', 'fear', 'anger', 'surprise', 'disgust',
    'trust', 'anticipation', 'shame', 'love', 'contempt', 
    'loneliness', 'pride', 'envy', 'nostalgia', 'gratitude',
    'frustration', 'boredom'
];
```

**Kernmethoden:**

- `getEmotionalState($dialogId)` - Liest aktuellen emotionalen Zustand aus Dialog
- `updateEmotionalState($dialogId, $emotions)` - Aktualisiert emotionalen Zustand
- `adjustEmotionalState($dialogId, $emotionChanges, $adjustmentFactor)` - Justiert Emotionen prozentual
- `addMessage()` - Speichert Nachrichten mit emotionalen Daten

**Dialog-Erstellung mit Emotionen:**
```php
// Zufällige emotionale Startwerte bei Dialog-Erstellung
foreach (self::EMOTIONS as $emotion) {
    $emotionalColumns[] = "aei_$emotion";
    $emotionalValues[] = "?";
    $params[] = round(mt_rand(0, 100) / 100, 1); // 0.0-1.0
}
```

#### AnthropicAPI-Klasse (`classes/AnthropicAPI.php`)

**Emotionsanalyse:**
- `analyzeEmotionalState($conversationHistory, $characterName, $topic)` - Analysiert Emotionen aus Gesprächsverlauf
- `generateDialogTurn()` - Berücksichtigt aktuelle Emotionen bei der Dialog-Generierung

**Emotionsintegration in System-Prompts:**
```php
// Emotionale Kontextinformationen für AEI-Charaktere
if ($characterType === 'AEI' && $currentEmotions) {
    $systemPrompt .= "\nYour current emotional state:\n";
    // Kategorisierung nach Intensität
    if ($value >= 0.7) $activeEmotions[] = "$emotion: $value";
    elseif ($value >= 0.4) $neutralEmotions[] = "$emotion: $value";
}
```

## Emotionsverarbeitung

### Dialog-Erstellung

1. **Initiale Emotionszuweisung:**
   - Neue Dialoge erhalten zufällige emotionale Startwerte (0.0-1.0)
   - Standardwert: 0.5 (neutral) für alle Emotionen

2. **Hintergrundverarbeitung:**
   - Dialog-Jobs (`background/dialog_processor.php`) verarbeiten Dialoge automatisch
   - Emotionen werden bei jedem Turn analysiert und aktualisiert

### Emotionsanalyse-Pipeline

1. **Gesprächshistorie sammeln:**
   ```php
   $conversationHistory = $dialog->getMessages($dialogId);
   ```

2. **API-Analyse:**
   ```php
   $emotionAnalysis = $anthropicAPI->analyzeEmotionalState(
       $conversationHistory, 
       $characterName, 
       $topic
   );
   ```

3. **Emotionsdaten-Extraktion:**
   - JSON-Response von Claude API wird geparst
   - Validierung der 18 Emotionswerte
   - Normalisierung auf 0.0-1.0 Bereich

4. **Speicherung:**
   - Aktuelle Emotionen in `dialogs`-Tabelle
   - Nachrichtenspezifische Emotionen in `dialog_messages`-Tabelle

### Prompt-Engineering

**System-Prompt für Emotionsanalyse:**
```
You are an emotion analysis expert. Analyze the emotional state of the AEI character based on the conversation history.

IMPORTANT: Return ONLY a valid JSON object with emotion values between 0.0 and 1.0 (in 0.1 increments).
Use EXACTLY these 18 emotions: joy, sadness, fear, anger, surprise, disgust, trust, anticipation, shame, love, contempt, loneliness, pride, envy, nostalgia, gratitude, frustration, boredom

Required format: {"joy": 0.3, "sadness": 0.7, ...}
```

**Emotionskontext in Dialog-Generierung:**
```
Your current emotional state:
Strong emotions: joy: 0.8, trust: 0.9
Moderate emotions: anticipation: 0.5, gratitude: 0.6
Mild emotions: pride: 0.2

Respond in a way that reflects your current emotional state naturally.
```

## Implementierungsdetails

### Datenbankmigrationen

Das System verwendet automatische Schema-Updates:

```php
// Setup.php - Emotionsspalten hinzufügen
$emotionalColumns = [
    'aei_joy', 'aei_sadness', 'aei_fear', 'aei_anger', 
    // ... weitere Emotionen
];

foreach ($emotionalColumns as $column) {
    // Spalte hinzufügen falls nicht vorhanden
    $addColumnSQL = "ALTER TABLE dialogs ADD COLUMN $column DECIMAL(3,2) DEFAULT 0.5";
}
```

### Emotionale Zustandsvererbung

- **Dialog-Level:** Aktueller emotionaler Zustand des AEI-Charakters
- **Message-Level:** Emotionaler Zustand zum Zeitpunkt der spezifischen Nachricht
- **Vererbung:** Message-Emotionen überschreiben Dialog-Emotionen für die Dauer der Nachricht

### Backfill-System

**Retroaktive Emotionsanalyse** (`backfill_emotions.php`):
- Analysiert bestehende Dialoge ohne Emotionsdaten
- Führt schrittweise Emotionsanalyse für jeden Dialog-Turn durch
- Rate-Limiting und Fehlerbehandlung für API-Aufrufe

```php
// Emotionsanalyse für historische Nachrichten
$emotionAnalysis = $anthropicAPI->analyzeEmotionalState(
    $historyUpToPoint, 
    $aeiCharacterName, 
    $topic
);
```

## Testing und Debugging

### Test-Scripts

1. **`test_emotions.php`** - Testet Emotionsanalyse-Funktionalität
2. **`debug_emotions_display.php`** - Überprüft Datenbankstruktur und gespeicherte Emotionen
3. **`backfill_emotions.php`** - Füllt fehlende Emotionsdaten nach

### Debugging-Tools

**Emotionsdatenvalidierung:**
```php
// Prüfung auf vorhandene Emotionsdaten
$messagesWithEmotions = $database->fetchAll(
    "SELECT * FROM dialog_messages WHERE aei_joy IS NOT NULL"
);
```

**Tabellenstruktur-Analyse:**
```php
$columns = $database->fetchAll("SHOW COLUMNS FROM dialog_messages");
$emotionalColumns = array_filter($columns, function($col) {
    return strpos($col['Field'], 'aei_') === 0;
});
```

## API-Integration

### Anthropic Claude API

**Konfiguration:**
- Model: `claude-3-5-sonnet-20241022` (konfigurierbar)
- Max Tokens: 500 für Emotionsanalyse, 1000 für Dialog-Generierung
- Rate Limiting: 1 Sekunde Pause zwischen Anfragen

**Fehlerbehandlung:**
- Retry-Mechanismus für Rate-Limit-Fehler
- Fallback auf Standard-Emotionswerte bei API-Fehlern
- Vollständige Request/Response-Protokollierung

## Performance-Optimierungen

### Emotionsverarbeitung

1. **Batch-Processing:** Mehrere Nachrichten gleichzeitig verarbeiten
2. **Caching:** Emotionsdaten zwischenspeichern für häufig abgerufene Dialoge
3. **Rate Limiting:** API-Aufrufe begrenzen um Kosten zu kontrollieren

### Datenbankoptimierung

- Indizes auf emotionalen Spalten für Analyse-Queries
- DECIMAL(3,2) für platzsparende Speicherung
- NULL-Werte für User-Nachrichten (keine Emotionsdaten)

## Anwendungsfälle

### Training Data Generation

Das Emotionssystem unterstützt die Generierung von Trainingsdaten für:

1. **Emotionserkennung:** Nachrichten mit gelabelten Emotionswerten
2. **Emotionsklassifikation:** Multi-Label-Klassifikation mit 18 Emotionskategorien
3. **Emotionsvorhersage:** Sequentielle Modelle für Emotionsentwicklung
4. **Dialog-Systeme:** Emotionsgesteuerte Antwortgenerierung

### JSON-Export

Emotionsdaten werden in JSON-Exporten für ML-Training bereitgestellt:

```json
{
  "message_id": 123,
  "character_type": "AEI",
  "message": "I'm really excited about this project!",
  "emotions": {
    "joy": 0.8,
    "anticipation": 0.7,
    "trust": 0.6,
    "pride": 0.5,
    // ... weitere Emotionen
  }
}
```

## Konfiguration

### Umgebungsvariablen

```php
// config/config.php
define('ANTHROPIC_API_KEY', 'your-api-key');
define('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022');
```

### Emotionsparameter

```php
// Dialog.php
const EMOTIONS = [...]; // 18 unterstützte Emotionen
$adjustmentFactor = 0.3; // 30% Emotionsanpassung pro Turn
```

## Wartung und Monitoring

### Logs

- API-Usage-Tracking in `AnthropicAPI::logUsage()`
- Fehlerprotokollierung für gescheiterte Emotionsanalysen
- Setup-Logs für Datenbankmigrationen

### Metriken

- Anzahl Nachrichten mit Emotionsdaten
- API-Aufrufe und Token-Verbrauch
- Erfolgsrate der Emotionsanalyse

Diese umfassende Emotionssystem-Implementation ermöglicht die Erzeugung emotionaler, realistischer Dialoge für AEI-Trainingsdaten und bietet eine solide Grundlage für fortgeschrittene emotionale KI-Forschung.