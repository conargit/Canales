# ContactDiscovery Elite Fusion v25.1

El sistema más avanzado para descubrimiento, clasificación y análisis de empresas B2B.

## Características
- **Arquitectura Single-File:** Todo en `scan.php`.
- **Motor Semántico Elite:** Detección de rubros, sub-rubros y scoring ponderado.
- **Priority Queue:** Rastreo inteligente priorizando `/contacto`, `/servicios`.
- **Modos Adaptativos:** Turbo, Inteligente y Profundo.
- **CRM Integrado:** Gestión de prospectos con notas y estados.
- **Seguridad Enterprise:** Protección de datos y CSRF.

## Instalación
1. Sube `scan.php`.
2. Otorga permisos de escritura para la carpeta `contactdiscovery_data/`.
3. ¡Listo!

## CLI / Cron
`php scan.php --worker-all`
