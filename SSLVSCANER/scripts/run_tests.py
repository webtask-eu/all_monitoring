#!/usr/bin/env python3
"""
Test runner script for SS.lv Monitor
"""
import sys
import subprocess
from pathlib import Path

def run_tests():
    """Run all tests"""
    print("🧪 Running SS.lv Monitor Tests...")
    print("=" * 50)
    
    # Add src to path
    src_path = Path(__file__).parent.parent / "src"
    sys.path.insert(0, str(src_path))
    
    # Run unit tests
    print("\n📋 Running unit tests...")
    result = subprocess.run([
        sys.executable, "-m", "pytest", 
        "tests/unit/", 
        "-v", 
        "--tb=short",
        "--color=yes"
    ], cwd=Path(__file__).parent.parent)
    
    if result.returncode != 0:
        print("❌ Unit tests failed!")
        return False
    
    # Run integration tests
    print("\n🔗 Running integration tests...")
    result = subprocess.run([
        sys.executable, "-m", "pytest", 
        "tests/integration/", 
        "-v", 
        "--tb=short",
        "--color=yes"
    ], cwd=Path(__file__).parent.parent)
    
    if result.returncode != 0:
        print("❌ Integration tests failed!")
        return False
    
    print("\n✅ All tests passed!")
    return True

if __name__ == "__main__":
    success = run_tests()
    sys.exit(0 if success else 1)
