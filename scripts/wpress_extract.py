#!/usr/bin/env python3
"""Universal .wpress archive extractor — works with old and new formats."""
import os, sys

HEADER_SIZE = 4377
EOF_BLOCK = b'\x00' * HEADER_SIZE

def clean_str(data):
    null_idx = data.find(b'\x00')
    return data[:null_idx if null_idx >= 0 else None].decode('utf-8', errors='replace')

def extract(archive_path, output_dir):
    os.makedirs(output_dir, exist_ok=True)
    count = 0
    with open(archive_path, 'rb') as f:
        while True:
            header = f.read(HEADER_SIZE)
            if not header or len(header) < HEADER_SIZE or header == EOF_BLOCK:
                break
            try:
                name = clean_str(header[0:255])
                size_str = clean_str(header[255:269]).strip()
                size = int(size_str) if size_str else 0
                path = clean_str(header[281:4377])
            except:
                break
            if not name:
                break
            full_path = os.path.join(output_dir, path)
            os.makedirs(full_path, exist_ok=True)
            with open(os.path.join(full_path, name), 'wb') as out:
                remaining = size
                while remaining > 0:
                    chunk = f.read(min(1048576, remaining))
                    if not chunk:
                        break
                    out.write(chunk)
                    remaining -= len(chunk)
            count += 1
            if count % 500 == 0:
                print(f"  Extracted {count} files...")
    print(f"Done. Total {count} files.")

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Usage: wpress_extract.py archive.wpress output_dir/")
        sys.exit(1)
    extract(sys.argv[1], sys.argv[2])
