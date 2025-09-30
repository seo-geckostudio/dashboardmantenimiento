# Dashboard de Mantenimiento

Sistema de gestión y monitoreo para sitios WordPress.

## Descripción

Este proyecto proporciona un dashboard completo para la gestión y mantenimiento de sitios WordPress, incluyendo:

- Monitoreo de sitios
- Verificación de inmutabilidad
- Gestión de trabajos asíncronos
- Sistema de autenticación
- Panel de administración

## Estructura del Proyecto

- `app/dashboard/` - Aplicación principal del dashboard
- `app/agent/` - Agente para tareas automatizadas
- `app/modules/` - Módulos específicos (hardening, lock, permissions, scanner)
- `docker/` - Configuración de contenedores Docker
- `database/` - Migraciones de base de datos

## Instalación

1. Clonar el repositorio
2. Configurar variables de entorno
3. Ejecutar `docker-compose up -d`
4. Acceder al dashboard en `http://localhost:8080`

## Tecnologías

- PHP 8.1+
- MySQL 8.0
- Docker
- Nginx
- Twig Templates