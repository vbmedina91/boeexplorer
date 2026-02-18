# 📦 Ejemplos de datos procesados

Esta carpeta contiene **ejemplos reales** de datos procesados por BOE Explorer, extraídos el 17 de febrero de 2026.

## Antes vs Después

| Archivo | Descripción |
|---------|-------------|
| [`xml_original_boe.xml`](xml_original_boe.xml) | ⬅️ **ANTES**: XML crudo del BOE (~100 líneas, campos anidados, formato inconsistente) |
| [`ejemplo_licitacion_boe.json`](ejemplo_licitacion_boe.json) | ➡️ **DESPUÉS**: JSON limpio con todos los campos extraídos y normalizados |

## Los 3 parsers en acción

### 🏛️ BOE — Licitaciones ([ejemplo_licitacion_boe.json](ejemplo_licitacion_boe.json))
De un XML de ~100 líneas con campos `<dt>`/`<dd>` anidados a un JSON plano con:
- Importe parseado (`"5.785,12 euros"` → `5785.12`)
- Adjudicatario + NIF extraídos
- CPV, CCAA, procedimiento, PYME detectados
- Tipo de contrato clasificado

### 💰 BDNS — Subvenciones ([ejemplo_subvencion_bdns.json](ejemplo_subvencion_bdns.json))
Convocatoria de subvención obtenida de la API del BDNS:
- Nivel administrativo (estatal/autonómica/local)
- Órgano convocante
- Clasificación MRR (Mecanismo de Recuperación y Resiliencia)

### 🏢 BORME — Registro Mercantil ([ejemplo_borme_empresa.json](ejemplo_borme_empresa.json))
De un PDF de texto plano del Registro Mercantil a JSON estructurado:
- Empresa y provincia identificadas
- Actos mercantiles clasificados (nombramientos, ceses, disolución)
- Personas extraídas con cargo y tipo de acción (NLP sobre texto libre)
- Datos registrales parseados

## ¿Cómo se generan?

```bash
# El cron diario procesa todo automáticamente
php cron_update.php

# O puedes ejecutar cada parser por separado
php -r 'require "api/boe_parser.php"; /* ... */'
```

Los datos se almacenan en `api/data/` como archivos JSON organizados por fecha.
