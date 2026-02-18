# 🇪🇸 BOE Explorer — Plataforma de Transparencia del Estado Español

<p align="center">
  <img src="screen.png" alt="BOE Explorer Dashboard" width="800"/>
</p>

<p align="center">
  <strong>🌐 Demo en vivo: <a href="https://test.pro-eurtec.com/">https://test.pro-eurtec.com/</a></strong>
</p>

> **Plataforma open-source de análisis, enriquecimiento y visualización de datos públicos del Boletín Oficial del Estado (BOE), Base de Datos Nacional de Subvenciones (BDNS) y Boletín Oficial del Registro Mercantil (BORME).**

[![PHP 8.x](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Chart.js 4.4.1](https://img.shields.io/badge/Chart.js-4.4.1-FF6384?logo=chartdotjs&logoColor=white)](https://www.chartjs.org/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-06B6D4?logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Demo](https://img.shields.io/badge/Demo-Live-brightgreen)](https://test.pro-eurtec.com/)

---

## 📋 Índice

- [Descripción](#-descripción)
- [Características principales](#-características-principales)
- [Arquitectura del sistema](#-arquitectura-del-sistema)
- [Fuentes de datos](#-fuentes-de-datos)
- [Stack tecnológico](#-stack-tecnológico)
- [Algoritmos y NLP](#-algoritmos-y-nlp)
- [API REST](#-api-rest)
- [Almacenamiento de datos](#-almacenamiento-de-datos)
- [Instalación](#-instalación)
- [Configuración del cron](#-configuración-del-cron)
- [Contribuir al proyecto](#-contribuir-al-proyecto)
- [Roadmap](#-roadmap)
- [Licencia](#-licencia)

---

## 🎯 Descripción

BOE Explorer es una plataforma **100% open-source** que agrega, enriquece y cruza datos de las tres principales fuentes de datos abiertos del Estado español:

| Fuente | Datos |
|--------|-------|
| **BOE** | Legislación, licitaciones, adjudicaciones, nombramientos, convenios |
| **BDNS** | Subvenciones públicas, convocatorias, destinatarios |
| **BORME** | Registro mercantil, socios, administradores, cargos empresariales |

El objetivo es **democratizar el acceso** a la información pública, facilitando la detección de patrones de gasto, concentración empresarial, y correlaciones entre regulaciones y contratación pública.

---

## ✨ Características principales

### 📊 Dashboard multi-fuente
- Agregación diaria automática de BOE + BDNS + BORME
- KPIs en tiempo real: documentos del día, tendencias semanales/mensuales
- Gráficos interactivos con drill-down (click en cualquier segmento para ver el detalle)

### 🔍 Motor de búsqueda avanzado
- Búsqueda federada unificada (BOE + Licitaciones + Subvenciones simultáneamente)
- Filtros combinables: empresa, NIF/CIF, departamento, tipo, CCAA, rango de importes, procedimiento
- Búsqueda accent-insensitive (encontrar "García" buscando "garcia")

### 💰 Análisis de licitaciones
- Enriquecimiento XML individual de cada licitación
- Extracción de importes (6 patrones prioritarios: adjudicación > estimado > presupuesto)
- Datos de adjudicatario + NIF, CPV, procedimiento, ofertas mayor/menor (13.2/13.3)
- Desglose por departamento, empresa, tipo de contrato, CCAA, sector y timeline

### 🔗 Motor de referencias cruzadas
- Correlación automática entre documentos BOE y licitaciones
- Scoring multi-signal con confianza ponderada (0.0–1.0)
- Detección de afinidad departamental, coincidencia de keywords, solapamiento de términos

### 🏢 Inteligencia empresarial (BORME)
- Parsing de PDFs del Registro Mercantil con `pdftotext`
- Extracción de socios, administradores, consejeros (30+ tipos de cargo)
- Seguimiento de nombramientos y ceses con estado activo/cesado
- 92,000+ empresas indexadas

### 🎯 Análisis de subvenciones (BDNS)
- Clasificación por sector, nivel administrativo, entidad y destino
- Detección de destinos internacionales (80+ países, 6 regiones)
- Timeline de convocatorias con tendencias

### ⚠️ Alertas de transparencia
- Concentración de contratos por empresa (flag ≥3, alerta alta ≥5)
- Recurrencia empresa-departamento
- Clasificación de 17 tipos de entidad jurídica española por NIF/CIF

---

## 🏗 Arquitectura del sistema

```
┌─────────────────────────────────────────────────────────┐
│                    FRONTEND (SPA)                        │
│              index.html (~3,000 líneas)                  │
│     Vanilla JS · Chart.js 4.4.1 · Tailwind CSS          │
│     71 funciones · 20 gráficos · Dark/Light mode         │
└───────────────────────┬─────────────────────────────────┘
                        │ HTTP/JSON
┌───────────────────────┴─────────────────────────────────┐
│                     API REST (PHP 8.x)                   │
│                  api/index.php (router)                   │
│                    16 endpoints                          │
├─────────────┬──────────────┬──────────────┬─────────────┤
│ boe_parser  │ bdns_parser  │ borme_parser │ cross_ref   │
│   375 LoC   │   680 LoC    │   727 LoC    │  194 LoC    │
├─────────────┴──────────────┴──────────────┴─────────────┤
│              data_store.php (743 LoC)                    │
│      Búsqueda · Análisis · Sectores · Empresas          │
├─────────────────────────────────────────────────────────┤
│                  config.php (151 LoC)                    │
│         Cache · HTTP client · Normalización              │
└───────────────────────┬─────────────────────────────────┘
                        │ Flat-file JSON
┌───────────────────────┴─────────────────────────────────┐
│                 ALMACENAMIENTO                           │
│   api/data/boe/YYYY-MM-DD.json  (557+ días)             │
│   api/data/bdns/convocatorias.json (10K+ registros)      │
│   api/data/borme/YYYY-MM-DD.json (42+ días)              │
│   api/data/borme/index.json (índice invertido)           │
│   api/cache/*.json (TTL: 5-60 min)                       │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│              FUENTES DE DATOS EXTERNAS                   │
│                                                          │
│  BOE XML API ─── BDNS REST API ─── BORME PDF/JSON API   │
│  boe.es          pap.hacienda.gob.es   boe.es/borme     │
└─────────────────────────────────────────────────────────┘
```

---

## 📡 Fuentes de datos

| Fuente | Protocolo | URL Base | Formato |
|--------|-----------|----------|---------|
| **BOE** (Boletín Oficial del Estado) | REST/XML | `https://www.boe.es/datosabiertos/api` | XML (sumarios + documentos individuales) |
| **BDNS** (Base Nacional de Subvenciones) | REST/JSON | `https://www.pap.hacienda.gob.es/bdnstrans/api` | JSON (con autenticación XSRF + cookies) |
| **BORME** (Registro Mercantil) | REST/JSON + PDF | `https://www.boe.es/datosabiertos/api/borme/` | JSON (sumario) + PDF (actas) |
| **Contratación del Estado** | ATOM Feed | `https://contrataciondelestado.es` | XML/ATOM (parser legacy) |

---

## 🛠 Stack tecnológico

### Backend
| Tecnología | Uso |
|-----------|-----|
| **PHP 8.x** (vanilla, sin framework) | API REST, parsers, procesamiento |
| **cURL** (ext-curl) | HTTP client para APIs externas |
| **pdftotext** (poppler-utils) | Extracción de texto de PDFs BORME |
| **JSON flat-file** | Almacenamiento sin base de datos |
| **OPcache** | Caché de bytecode PHP |

### Frontend
| Tecnología | Versión | Uso |
|-----------|---------|-----|
| **Vanilla JavaScript** | ES2021+ | SPA, 71 funciones, async/await |
| **Chart.js** | 4.4.1 | 20 gráficos interactivos con drill-down |
| **Tailwind CSS** | 3.x (CDN) | Diseño responsive, dark mode |
| **Inter** (Google Fonts) | Variable weight | Tipografía |
| **Material Symbols** | Outlined | Iconografía |

### Infraestructura
| Componente | Detalle |
|-----------|---------|
| **Servidor** | Debian Linux, Virtualmin |
| **PHP-FPM** | Worker pool con OPcache |
| **Cron** | Actualización diaria automática |
| **Caché** | Archivos JSON, TTL configurable (5–60 min) |

**Total: ~7,700 líneas de código** (sin datos ni tests)

---

## 🧠 Algoritmos y NLP

### 1. Motor de Referencias Cruzadas
**Archivo:** `api/cross_reference.php` (194 LoC)

Scoring multi-señal que combina 5 dimensiones para calcular la confianza (0.0 – 1.0) de la correlación entre un documento BOE y una licitación:

| Señal | Peso máximo | Método |
|-------|-------------|--------|
| Keywords temáticos | +0.15/categoría | 8 categorías × ~10 keywords cada una |
| Afinidad departamental | +0.30 | 13 diccionarios de alias ministeriales |
| Solapamiento de palabras | +0.30 | Tokens ≥4 caracteres, Jaccard-like |
| Afinidad tipo documento | +0.10 | Bonus para tipos de contratación |
| Matching de referencia ID | +0.50 | Coincidencia exacta de identificadores |

**Niveles de confianza:** Alta (≥70%), Media (40-70%), Baja (20-40%), Muy baja (<20%)

### 2. Clasificador de sectores
**Archivos:** `api/data_store.php` + `api/bdns_parser.php`

Clasificador NLP basado en keywords con **14 sectores** y **100+ keywords por conjunto**, aplicado tanto a licitaciones como a subvenciones:

```
Salud · Educación · Cultura · Vivienda · Medio Ambiente
Agricultura · Industria · Transporte · Empleo · Seguridad
Justicia · Cooperación · Digitalización · Servicios Generales
```

Técnicas: normalización accent-insensitive, lowercase matching, priorización por especificidad.

### 3. Parser BORME (NLP sobre PDF)
**Archivo:** `api/borme_parser.php` (727 LoC)

Pipeline de extracción:
```
PDF → pdftotext -layout → Limpieza headers/footers → Split por nº registro
→ Detección de secciones (Nombramientos/Ceses) → Extracción de personas
→ Clasificación de 30+ tipos de cargo → Merge activo/cesado
```

**Cargos reconocidos:** Administrador Único, Consejero Delegado, Presidente, Secretario, Apoderado, Liquidador, Auditor, Representante, Socio, y 20+ variantes.

### 4. Enriquecimiento de licitaciones
**Archivo:** `api/boe_parser.php` (375 LoC)

Cada licitación se enriquece individualmente fetching su XML del BOE:
- **6 patrones de extracción de importe** (prioridad: adjudicación > estimado > presupuesto > oferta)
- **Parsing de números españoles** (1.234.567,89 → float)
- **Extracción de adjudicatario** + NIF/CIF (campos 12.1, 12.2)
- **Detección PYME**, códigos CPV, tipo de procedimiento
- **Ofertas mayor/menor** (campos 13.2, 13.3)

### 5. Detección de destinos internacionales
**Archivo:** `api/bdns_parser.php`

Clasificador geo-NLP:
- **80+ países** con variantes de gentilicio y nombre
- **6 agrupaciones regionales** (África, América Latina, Asia, UE, Oriente Medio, Países en desarrollo)
- Word-boundary regex para keywords cortos (≤6 chars) para evitar falsos positivos

### 6. Clasificación de entidades jurídicas
**Archivo:** `api/data_store.php`

Identificación de **17 tipos de entidad** a partir del prefijo NIF/CIF:
```
A → S.A. | B → S.L. | C → S.Colectiva | D → S.Comanditaria
E → Comunidad | F → Cooperativa | G → Asociación | H → C.Propietarios
J → Civil | N → Extranjera | P → Corporación | Q → Organismo Público
R → Congregación | S → Estatal/Autonómico | U → UTE | V → Otros | W → Sucursal Extranjera
```

### 7. Alertas de transparencia (Red Flags)
Detección automática de:
- **Concentración**: empresa con ≥3 contratos → flag, ≥5 → alerta alta
- **Recurrencia**: misma empresa + mismo departamento ≥2 veces
- **Análisis PYME vs Gran empresa** por volumen de adjudicación

---

## 📡 API REST

**Base URL:** `/api/?action=`

### Endpoints principales (16)

| Endpoint | Params | Descripción |
|----------|--------|-------------|
| `status` | — | Salud del sistema, versión, conteos |
| `dashboard` | — | KPIs del día, tendencias, últimos documentos |
| `documentos` | `texto, departamento, seccion, tipo, fecha_*` | Búsqueda de documentos BOE (paginado) |
| `licitaciones` | `texto, empresa, nif, tipo, departamento, ccaa, importe_min/max, procedimiento, fecha_*` | Búsqueda de licitaciones (paginado) |
| `referencias` | `confianza_min, limite` | Referencias cruzadas BOE↔Licitaciones |
| `resumen-gasto` | `periodo` (diario/semanal/mensual) | Análisis de gasto por múltiples dimensiones |
| `analisis-empresas` | — | Concentración empresarial + alertas |
| `analisis-tematico` | — | Análisis temático por keywords |
| `subvenciones` | — | Analytics de BDNS (sector, nivel, destino, timeline) |
| `subvenciones-buscar` | `texto, nivel, sector, fecha_*` | Búsqueda de convocatorias BDNS (paginado) |
| `subvenciones-chart-detalle` | `campo, valor` | Drill-down de gráficos BDNS |
| `subvenciones-destino-detalle` | `destino` | Detalle por destino internacional |
| `busqueda-global` | `q` | Búsqueda federada (BOE + Licitaciones + Subvenciones) |
| `socios` | `empresa` | Socios/cargos de empresa desde BORME |
| `borme-status` | — | Estado de procesamiento BORME |
| `departamentos` | — | Lista de departamentos únicos (30 días) |

### Ejemplo de uso

```bash
# Buscar licitaciones de Defensa con importe > 1M€
curl "https://tu-dominio.com/api/?action=licitaciones&departamento=Defensa&importe_min=1000000"

# Buscar socios de Telefónica en BORME
curl "https://tu-dominio.com/api/?action=socios&empresa=TELEFONICA"

# Análisis de gasto mensual
curl "https://tu-dominio.com/api/?action=resumen-gasto&periodo=mensual"
```

---

## 💾 Almacenamiento de datos

### Filosofía: Zero-Database

BOE Explorer usa **almacenamiento flat-file JSON** intencionalmente:

- ✅ **Sin dependencias** — No necesita MySQL, PostgreSQL ni MongoDB
- ✅ **Portabilidad total** — Los datos son archivos legibles por humanos
- ✅ **Backup trivial** — `cp -r api/data/ backup/`
- ✅ **Versionable** — Compatible con git (aunque no recomendado por volumen)
- ✅ **Procesamiento incremental** — Un archivo por día, merge selectivo

### Estructura

```
api/data/
├── meta.json                     # Índice global: conteos diarios, rango de fechas, totales
├── boe/
│   └── YYYY-MM-DD.json           # Array de documentos BOE del día (~557 archivos)
├── bdns/
│   ├── convocatorias.json        # Todas las convocatorias (merge deduplicado por ID)
│   ├── taxonomias.json           # Datos de referencia (regiones, sectores, etc.)
│   └── meta.json                 # Metadata de actualización BDNS
├── borme/
│   ├── YYYY-MM-DD.json           # Actas parseadas de todas las provincias del día
│   ├── index.json                # Índice invertido empresa → fechas
│   └── meta.json                 # Metadata de procesamiento BORME
└── .htaccess                     # "Deny from all" (seguridad)
```

### Caché

```
api/cache/
├── {md5_hash}.json               # Cache con TTL configurable por endpoint
└── .htaccess                     # "Deny from all"
```

| Endpoint | TTL |
|----------|-----|
| BOE día | 10 min |
| Dashboard | 5 min (rendered: 1h) |
| Licitaciones | 15 min |
| Analytics | 1 hora |

---

## 🚀 Instalación

### Requisitos previos

- **PHP 8.0+** con extensiones: `curl`, `json`, `mbstring`, `xml`
- **pdftotext** (poppler-utils) para parsing BORME
- **Servidor web** con soporte PHP (Apache/Nginx)
- **~500 MB** de espacio en disco para datos históricos

### Pasos

```bash
# 1. Clonar el repositorio
git clone https://github.com/vbmedina91/boeexplorer.git
cd boeexplorer

# 2. Instalar pdftotext (si no está instalado)
sudo apt-get install poppler-utils

# 3. Crear directorios de datos
mkdir -p api/data/boe api/data/bdns api/data/borme api/cache

# 4. Configurar permisos
chmod 755 api/data api/cache
chown -R www-data:www-data api/data api/cache

# 5. Proteger directorios de datos (si usas Apache)
echo "Deny from all" > api/data/.htaccess
echo "Deny from all" > api/cache/.htaccess

# 6. Ejecutar primera carga de datos
php cron_update.php

# 7. (Opcional) Backfill histórico — carga los últimos N días
php backfill.php --days=30

# 8. Abrir en el navegador
# http://tu-dominio.com/
```

### Verificar instalación

```bash
# Comprobar estado del sistema
curl "http://tu-dominio.com/api/?action=status"

# Resultado esperado:
# {"version":"2.1.0","total_documentos":119000,...}
```

---

## ⏰ Configuración del cron

```cron
# Actualización diaria a las 20:00 (cuando BOE publica el sumario del día siguiente)
0 20 * * * cd /ruta/al/proyecto && php cron_update.php >> /var/log/boe_cron.log 2>&1
```

El cron ejecuta secuencialmente:
1. **Fetch BOE** — Descarga sumario del día y clasifica documentos
2. **Enriquecimiento** — Fetch XML individual de cada licitación para importes, adjudicatarios
3. **BDNS Update** — Descarga nuevas convocatorias de subvenciones
4. **BORME Update** — Descarga y parsea PDFs del Registro Mercantil
5. **Limpieza de caché** — Invalida cachés afectados

---

## 🤝 Contribuir al proyecto

¡Las contribuciones son bienvenidas! BOE Explorer es un proyecto comunitario para mejorar la transparencia del Estado español.

### ¿Cómo puedes contribuir?

#### 🐛 Reportar bugs
Abre un [issue](https://github.com/vbmedina91/boeexplorer/issues) describiendo:
- Qué esperabas vs qué ocurrió
- Pasos para reproducir
- Capturas de pantalla si aplica

#### 💡 Proponer mejoras
Abre un issue con la etiqueta `enhancement` describiendo:
- El problema que resuelve
- Propuesta de solución
- Impacto esperado

#### 🔧 Contribuir código

1. **Fork** del repositorio
2. **Crea una rama** para tu feature: `git checkout -b feature/mi-mejora`
3. **Haz commit** de tus cambios: `git commit -m "Añadir: descripción"`
4. **Push** a tu fork: `git push origin feature/mi-mejora`
5. **Abre un Pull Request** describiendo los cambios

#### 📝 Mejorar documentación
- Corregir errores en el README
- Añadir ejemplos de uso de la API
- Traducir a otros idiomas (inglés, catalán, etc.)

### Áreas donde más se necesita ayuda

| Área | Descripción | Dificultad |
|------|-------------|------------|
| **Nuevos parsers** | Parsear DOGC (Cataluña), BOJA (Andalucía), otros boletines autonómicos | Media-Alta |
| **Machine Learning** | Reemplazar clasificadores de keywords por modelos NLP (spaCy, BERT) | Alta |
| **Base de datos** | Migración opcional a SQLite/PostgreSQL para mejor rendimiento | Media |
| **Tests** | Crear suite de tests unitarios (PHPUnit) | Media |
| **Visualización** | Nuevos tipos de gráficos, mapas interactivos de España | Media |
| **API pública** | Documentación OpenAPI/Swagger, rate limiting, API keys | Media |
| **Mobile** | App React Native o PWA mejorada | Alta |
| **Accesibilidad** | Mejoras WCAG 2.1, navegación por teclado | Baja-Media |
| **Internacionalización** | i18n para interfaz multilingüe | Baja |
| **Datos abiertos** | Exportación CSV/Excel/JSON de resultados | Baja |

### Convenciones de código

- **PHP:** Sin framework, código limpio y documentado con PHPDoc
- **JS:** Vanilla ES2021+, funciones con nombres descriptivos
- **Commits:** En español, formato `Tipo: descripción` (Añadir, Corregir, Mejorar, Refactor)
- **Sin dependencias externas** (Composer/npm) — el proyecto funciona con `git clone` + PHP

---

## 🗺 Roadmap

- [ ] Boletines autonómicos (DOGC, BOJA, BOCM, etc.)
- [ ] Exportación de datos (CSV, Excel, JSON)
- [ ] Mapas interactivos por CCAA con datos georreferenciados
- [ ] Alertas personalizadas por email/Telegram
- [ ] API pública con documentación Swagger
- [ ] Tests unitarios y CI/CD
- [ ] Migración opcional a SQLite para mejor rendimiento
- [ ] PWA con soporte offline
- [ ] Análisis de redes: grafos de relaciones empresa-departamento
- [ ] Machine Learning para clasificación temática

---

## 📄 Licencia

Este proyecto está bajo la licencia **MIT**. Ver [LICENSE](LICENSE) para más detalles.

---

## 🙏 Créditos

- **Datos:** [BOE](https://www.boe.es/datosabiertos/), [BDNS](https://www.pap.hacienda.gob.es/bdnstrans/), [BORME](https://www.boe.es/diario_borme/)
- **Herramientas:** PHP, Chart.js, Tailwind CSS, poppler-utils

---

<p align="center">
  <strong>Hecho con ❤️ para la transparencia del Estado español</strong><br/>
  <a href="https://github.com/vbmedina91/boeexplorer">⭐ Dale una estrella si te resulta útil</a>
</p>
