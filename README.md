# DevKH Map Editor - Backend API

Laravel 12.x backend API for the DevKH Map Editor. Provides REST API endpoints for geospatial data management with PostGIS.

## Tech Stack

- **Framework**: Laravel 12.x
- **Database**: PostgreSQL 15+ with PostGIS extension
- **Authentication**: Laravel Sanctum
- **API**: RESTful API with GeoJSON support
- **Spatial Operations**: PostGIS functions (ST_Union, ST_Buffer, ST_IsValid, etc.)
- **Testing**: Pest PHP

## Prerequisites

- PHP 8.2+
- PostgreSQL 15+ with PostGIS extension
- Composer
- ogr2ogr (GDAL) for Shapefile support

## Installation

```bash
# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=arcgiskh_api
# DB_USERNAME=your_username
# DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Start development server
php artisan serve
```

The API will be available at `http://localhost:8000`

## PostGIS Setup

Enable PostGIS extension in PostgreSQL:

```sql
-- Connect to your database
psql -U postgres -d arcgiskh_api

-- Enable PostGIS
CREATE EXTENSION IF NOT EXISTS postgis;

-- Verify installation
SELECT PostGIS_Version();
```

## API Endpoints

### Authentication

- `POST /api/register` - User registration
- `POST /api/login` - User login
- `POST /api/logout` - User logout

### Layers

- `GET /api/layers` - List all layers
- `POST /api/layers` - Create new layer
- `GET /api/layers/{id}` - Get layer details
- `PUT /api/layers/{id}` - Update layer
- `DELETE /api/layers/{id}` - Delete layer
- `GET /api/layers/{id}/features` - Get layer features
- `GET /api/layers/{id}/validate` - Validate layer topology

### Features

- `POST /api/features` - Create feature
- `GET /api/features/{id}` - Get feature
- `PUT /api/features/{id}` - Update feature
- `DELETE /api/features/{id}` - Delete feature
- `POST /api/features/bulk` - Bulk operations
- `POST /api/features/import` - Import features
- `POST /api/features/spatial-query` - Spatial queries
- `POST /api/features/spatial-operation` - Spatial operations

### Import/Export

- `POST /api/import/geojson` - Import GeoJSON
- `POST /api/import/kml` - Import KML
- `POST /api/import/shapefile` - Import Shapefile (.zip)
- `GET /api/layers/{id}/export/geojson` - Export GeoJSON
- `GET /api/layers/{id}/export/kml` - Export KML
- `GET /api/layers/{id}/export/csv` - Export CSV
- `GET /api/layers/{id}/export/shapefile` - Export Shapefile

### Audit Logs

- `GET /api/audit-logs` - List all audit logs
- `GET /api/audit-logs/summary` - Get audit summary
- `GET /api/audit-logs/{id}` - Get single audit log
- `GET /api/features/{featureId}/audit-logs` - Get feature audit logs
- `GET /api/layers/{layerId}/audit-logs` - Get layer audit logs

### Versioning

- `GET /api/features/{feature}/versions` - Get feature versions
- `GET /api/versions/{id}` - Get version details
- `POST /api/versions/{id}/restore` - Restore to version
- `POST /api/versions/compare` - Compare two versions
- `GET /api/features/{featureId}/versions/{versionNumber}` - Get feature at version

## Development

```bash
# Run tests
php artisan test

# Code formatting
./vendor/bin/pint

# Run development server
php artisan serve

# Run queue worker
php artisan queue:work

# View logs
php artisan pail

# Run all services
npm run dev
```

## Project Structure

```
app/
├── Http/
│   └── Controllers/
│       └── Api/           # API Controllers
├── Models/                # Eloquent Models
├── Services/              # Business Logic Services
│   ├── AuditService.php   # Audit logging
│   └── VersioningService.php  # Version control
└── ...

database/
└── migrations/            # Database Migrations

routes/
└── api.php               # API Routes

tests/                    # Pest Tests
```

## Environment Variables

```env
APP_NAME="DevKH Map Editor"
APP_ENV=local
APP_KEY=base64:your-key
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=arcgiskh_api
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

## PostGIS Functions Used

- `ST_Union` - Combine geometries
- `ST_Difference` - Subtract geometries
- `ST_Intersection` - Find intersection
- `ST_Split` - Split geometries
- `ST_Buffer` - Create buffer zones
- `ST_IsValid` - Check geometry validity
- `ST_MakeValid` - Repair invalid geometries
- `ST_Overlaps` - Check for overlaps
- `ST_Equals` - Check for duplicates
- `ST_Intersects` - Check for intersections
- `ST_DWithin` - Distance search
- `ST_Contains` - Point-in-polygon test

## Deployment

1. Set `APP_ENV=production`
2. Set `APP_DEBUG=false`
3. Configure production database
4. Run migrations: `php artisan migrate --force`
5. Optimize: `php artisan optimize`
6. Configure web server (nginx/apache)

## License

This project is licensed under the MIT License.
