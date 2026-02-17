# Contribuir a BOE Explorer

¡Gracias por tu interés en contribuir a BOE Explorer! Este proyecto busca mejorar la transparencia del Estado español y toda ayuda es bienvenida.

## Primeros pasos

1. **Fork** del repositorio en GitHub
2. **Clona** tu fork:
   ```bash
   git clone https://github.com/TU-USUARIO/boeexplorer.git
   cd boeexplorer
   ```
3. **Configura** el entorno (ver README.md → Instalación)
4. **Crea una rama** para tu trabajo:
   ```bash
   git checkout -b feature/mi-mejora
   ```

## Estructura del proyecto

```
├── index.html              # Frontend SPA (todo el código del cliente)
├── api/
│   ├── index.php           # Router API (16 endpoints)
│   ├── config.php          # Configuración, caché, helpers
│   ├── boe_parser.php      # Parser XML del BOE
│   ├── bdns_parser.php     # Parser API BDNS (subvenciones)
│   ├── borme_parser.php    # Parser PDF BORME (registro mercantil)
│   ├── cross_reference.php # Motor de referencias cruzadas
│   ├── data_store.php      # Almacenamiento y búsqueda
│   ├── data/               # Datos JSON (NO se sube al repo)
│   └── cache/              # Caché temporal (NO se sube al repo)
├── cron_update.php         # Script de actualización diaria
├── README.md
├── CONTRIBUTING.md         # Este archivo
└── LICENSE                 # MIT
```

## Convenciones

### Código PHP
- PHP 8.0+ estricto
- Sin framework ni Composer (dependencia cero)
- Funciones documentadas con PHPDoc
- Nombres de función en `snake_case`
- Manejo de errores con `try/catch` y logging

### Código JavaScript
- Vanilla ES2021+ (sin frameworks)
- Funciones con nombres descriptivos en `camelCase`
- `async/await` para operaciones asíncronas
- Sin `var` — usar `const` y `let`

### Commits
- En español
- Formato: `Tipo: descripción breve`
- Tipos: `Añadir`, `Corregir`, `Mejorar`, `Refactor`, `Docs`, `Test`
- Ejemplo: `Añadir: parser para BOJA (Boletín de Andalucía)`

### Pull Requests
- Título descriptivo en español
- Descripción de qué cambios se hacen y por qué
- Captura de pantalla si hay cambios visuales
- Mantener PRs pequeños y enfocados (1 feature por PR)

## Áreas de contribución

### 🟢 Fácil (buen primer issue)
- Mejorar textos y tooltips de la interfaz
- Añadir más keywords a los clasificadores de sectores
- Exportación de tablas a CSV
- Mejoras de accesibilidad (ARIA, contraste, teclado)
- Traducciones (inglés, catalán, gallego, euskera)

### 🟡 Medio
- Nuevos tipos de gráficos (mapas, sankey, treemap)
- Tests unitarios con PHPUnit
- Nuevos filtros de búsqueda
- Documentación OpenAPI/Swagger de la API
- Optimizaciones de rendimiento del parser

### 🔴 Avanzado
- Parsers para boletines autonómicos (DOGC, BOJA, BOCM, etc.)
- Migración opcional a SQLite/PostgreSQL
- Machine Learning para clasificación (spaCy, BERT)
- Grafos de relaciones empresa-departamento (D3.js/Sigma.js)
- Progressive Web App con soporte offline

## Reportar bugs

Abre un [issue](https://github.com/vbmedina91/boeexplorer/issues) con:

1. **Título claro** describiendo el problema
2. **Pasos para reproducir** el bug
3. **Resultado esperado** vs **resultado actual**
4. **Capturas de pantalla** si aplica
5. **Entorno**: navegador, versión PHP, sistema operativo

## Proponer mejoras

Abre un issue con la etiqueta `enhancement`:

1. **Problema que resuelve** o caso de uso
2. **Propuesta de solución** (puede ser simple)
3. **Alternativas consideradas**
4. **Mockup/wireframe** si hay cambios de UI

## Código de conducta

- Trata a todos con respeto
- Las críticas deben ser constructivas
- Céntrate en el código, no en la persona
- Acepta feedback con apertura

## ¿Preguntas?

Abre un issue con la etiqueta `question` o contacta al mantenedor a través de GitHub.

---

*¡Gracias por ayudar a hacer más transparente la administración pública española!* 🇪🇸
