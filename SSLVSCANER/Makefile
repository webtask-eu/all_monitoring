# SS.lv Monitor Bot Makefile

.PHONY: help install test run clean lint format

# Default target
help:
	@echo "SS.lv Monitor Bot - Available commands:"
	@echo "  install    - Install dependencies"
	@echo "  test       - Run all tests"
	@echo "  test-unit  - Run unit tests only"
	@echo "  test-integration - Run integration tests only"
	@echo "  run        - Run the bot"
	@echo "  clean      - Clean up temporary files"
	@echo "  lint       - Run linting"
	@echo "  format     - Format code"

# Install dependencies
install:
	@echo "📦 Installing dependencies..."
	pip install -r requirements.txt

# Run all tests
test:
	@echo "🧪 Running all tests..."
	python scripts/run_tests.py

# Run unit tests only
test-unit:
	@echo "🧪 Running unit tests..."
	python -m pytest tests/unit/ -v --tb=short --color=yes

# Run integration tests only
test-integration:
	@echo "🔗 Running integration tests..."
	python -m pytest tests/integration/ -v --tb=short --color=yes

# Run the bot
run:
	@echo "🚀 Starting SS.lv Monitor Bot..."
	python main.py

# Clean up temporary files
clean:
	@echo "🧹 Cleaning up..."
	find . -type f -name "*.pyc" -delete
	find . -type d -name "__pycache__" -delete
	find . -type f -name "*.log" -delete
	find . -type f -name "*.db" -delete
	rm -rf .pytest_cache/
	rm -rf htmlcov/
	rm -rf .coverage

# Run linting
lint:
	@echo "🔍 Running linting..."
	python -m flake8 src/ tests/ --max-line-length=100 --ignore=E203,W503

# Format code
format:
	@echo "🎨 Formatting code..."
	python -m black src/ tests/ --line-length=100
	python -m isort src/ tests/ --profile=black
