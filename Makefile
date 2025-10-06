.PHONY: up down key fresh be fe tinker

up:
	docker compose up -d --build

down:
	docker compose down -v

key:
	docker compose exec backend php artisan key:generate

fresh:
	docker compose exec backend php artisan migrate:fresh --seed

clear:
	docker compose exec backend php artisan cache:clear
	docker compose exec backend php artisan config:clear
	docker compose exec backend php artisan config:cache
	docker compose exec backend php artisan route:clear
	docker compose exec backend php artisan route:cache

tinker:
	docker compose exec backend php artisan tinker

be:
	docker compose exec backend sh -lc "bash || sh"

fe:
	docker compose exec frontend sh -lc "sh"
