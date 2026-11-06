# test-manager-library-3239188-api

> **Prueba técnica SDD · Ficha ADSO 3239188**  
> Backend oficial en **PHP (Laravel)** para *Manager Library*.

Este repositorio contiene la implementación completa del backend de *Manager Library*, desarrollado estrictamente bajo la metodología **SDD (*Spec-Driven Development*)** traduciendo la especificación de referencia a **Laravel 11**.

---

## 1. Cumplimiento de la Constitución y Decisiones de Arquitectura

* **Artículo I a IV (Arquitectura Hexagonal & SOLID):** Separación de dominios, servicios de aplicación y controladores desacoplados.
* **Artículo V & ADR-001 (Propiedad del esquema):** Todo el DDL de la base de datos (tablas, índices, llaves foráneas y semilla) pertenece y es ejecutado por las migraciones de este repositorio.
* **Artículo VI & ADR-002 (Invariantes de motor y concurrencia):** 
  * Restricción `CHECK (available_copies >= 0 AND available_copies <= total_copies)`.
  * Restricción `CHECK (total_copies >= 1)`.
  * Índice único parcial `(isbn) WHERE deleted_at IS NULL`.
* **Artículo VII (Lo derivado se calcula):** El estado del préstamo (`open`, `overdue`, `closed`) y los días de retraso (`days_late`) se calculan en tiempo de ejecución; no existen columnas para ellos en la base de datos.
* **Artículo VIII & ADR-004 (Valores congelados):** La línea de préstamo (`loan_item`) congela el título, ISBN y nombre de categoría del instante del préstamo. El reporte de más prestados agrupa sobre estos valores congelados.
* **Artículo IX (Seguridad y Secretos):** Contraseñas hasheadas con Bcrypt; nunca viajan en claro ni aparecen en respuestas HTTP o logs.
* **Cuerpo de Error Único:** Todo error de negocio o validación responde bajo el estándar JSON:
  ```json
  {
    "code": "BOOK_NOT_AVAILABLE",
    "message": "...",
    "details": []
  }
  ```

---

## 2. Catálogo de Endpoints Implementados (`/api/v1`)

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| `GET` | `/health` | Público | Comprobación de salud: `{"status":"ok"}` |
| `POST` | `/auth/login` | Público | Inicio de sesión, retorna JWT de 8 horas |
| `POST` | `/users` | Admin | Da de alta bibliotecarios (`librarian`) |
| `GET` | `/categories` | Autenticado | Lista las 5 categorías sembradas |
| `GET` | `/books` | Autenticado | Búsqueda parcial (`q`), categoría y paginado |
| `GET` | `/books/{id}` | Autenticado | Detalle de libro activo |
| `POST` | `/books` | Admin | Crea libro con ejemplares disponibles = total |
| `PUT` | `/books/{id}` | Admin | Modifica datos y ajusta stock en diferencia |
| `DELETE` | `/books/{id}` | Admin | Baja lógica (impide baja si hay préstamos abiertos) |
| `PUT` | `/books/{id}/cover` | Admin | Sube portada JPG/PNG (máx 2 MB) |
| `DELETE` | `/books/{id}/cover` | Admin | Elimina portada |
| `GET` | `/books/{id}/cover` | Autenticado | Obtiene binario de la portada |
| `GET` | `/members` | Admin, Librarian | Lista y busca socios por nombre/documento |
| `GET` | `/members/{id}` | Admin, Librarian | Detalle de socio con conteo de préstamos activos |
| `POST` | `/members` | Admin, Librarian | Registra socio con documento único |
| `POST` | `/loans` | Admin, Librarian | Presta 1 a 3 libros (valida límite de 3 y cero vencidos) |
| `GET` | `/loans` | Admin, Librarian | Lista préstamos con filtros y estado calculado |
| `GET` | `/loans/{id}` | Admin, Librarian | Detalle de préstamo con sus líneas |
| `POST` | `/loans/{id}/items/{itemId}/return` | Admin, Librarian | Devolución por línea y reposición de ejemplar |
| `GET` | `/loans/overdue` | Admin, Librarian | Líneas vencidas con días de retraso calculados |
| `GET` | `/reports/most-borrowed` | Admin | Reporte agregado por valores congelados |

---

## 3. Puesta en Marcha

1. **Configurar Entorno:**
   ```bash
   cp .env.example .env
   ```

2. **Instalar Dependencias:**
   ```bash
   composer install
   ```

3. **Ejecutar Migraciones y Semillas:**
   ```bash
   php artisan migrate --seed
   ```

4. **Iniciar Servidor de Desarrollo:**
   ```bash
   php artisan serve --port=8000
   ```

O ejecutando con Docker:
```bash
docker build -t manager-library-api .
docker run -p 8000:8000 --env-file .env manager-library-api
```
