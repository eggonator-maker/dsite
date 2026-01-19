import json
import csv
import os
import shutil
import re
import unicodedata
from bs4 import BeautifulSoup

# Configuration
INPUT_JSON = os.path.join("scraped_data", "doctors_processed_2.json")
OUTPUT_CSV = os.path.join("scraped_data", "doctors_import_3.csv")
SOURCE_IMAGES_DIR = os.path.join("scraped_data", "images")
FLAT_IMAGES_DIR = os.path.join("scraped_data", "flat_images")

# Ensure flat directory exists
if os.path.exists(FLAT_IMAGES_DIR):
    shutil.rmtree(FLAT_IMAGES_DIR) # Clear previous run to avoid stale files
os.makedirs(FLAT_IMAGES_DIR, exist_ok=True)

# Fields to export to CSV
CSV_HEADERS = [
    "title",
    "field_primary_speciality",
    "field_image", # Will contain just the filename: "dr-name.jpg"
    "field_about_doctor",
    "field_competencies",
    "field_professional_experience",
    "field_education_training",
    "field_conferences_seminars",
    "field_awards_distinctions",
    "field_publications",
    "field_memberships",
    "field_original_url"
   # "centralizator_specialitate_normalized"
]

def clean_html_content(html_string):
    """Strips wrapper divs, keeps inner HTML"""
    if not html_string:
        return ""
    soup = BeautifulSoup(html_string, "html.parser")
    wrapper = soup.find(class_="wpb_wrapper")
    if wrapper:
        return wrapper.decode_contents().strip()
    found_containers = soup.find_all("div", class_=["wpb_text_column", "wpb_content_element"])
    if found_containers:
        for div in found_containers:
            div.unwrap()
        return str(soup).strip()
    return str(soup).strip()

def slugify(value):
    """Converts 'DR. ION POPESCU' to 'dr-ion-popescu'"""
    value = str(value)
    value = unicodedata.normalize('NFKD', value).encode('ascii', 'ignore').decode('ascii')
    value = re.sub(r'[^\w\s-]', '', value).lower()
    return re.sub(r'[-\s]+', '-', value).strip('-')

def main():
    if not os.path.exists(INPUT_JSON):
        print(f"Error: Input file '{INPUT_JSON}' not found.")
        return

    print(f"Reading {INPUT_JSON}...")
    with open(INPUT_JSON, 'r', encoding='utf-8') as f:
        data = json.load(f)

    print(f"Processing {len(data)} records...")

    with open(OUTPUT_CSV, 'w', encoding='utf-8', newline='') as csvfile:
        writer = csv.DictWriter(csvfile, fieldnames=CSV_HEADERS)
        writer.writeheader()
    
        for entry in data:
            row = {}
            doctor_name = entry.get("title", "doctor")
            #doc_split = doctor_name.lower().split(" ")
            #d_n = ""
            #for seg in doc_split:
            #    split_seg = list(seg)
            #    split_seg[0] = split_seg[0].upper()
            #    segg = "".join(split_seg)
            #    d_n += segg + " "
            #d_n = d_n.strip()

            # 1. Basic Fields
            row["title"] = doctor_name
            row["field_primary_speciality"] = entry.get("field_primary_speciality", "")
            row["field_original_url"] = entry.get("url", "")
            #row["centralizator_specialitate_normalized"] = f"{entry.get("centralizator_specialitate_normalized", "")}|"
            # 2. Image Flattening & Renaming
            # Logic: Find original file -> Rename to {slugified_name}.jpg -> Copy to flat_images
            image_data = entry.get("field_image", {})
            #original_rel_path = ""
            
            # Handle both dict format {"original": "path"} and string format "path"
            #if isinstance(image_data, dict):
            #    original_rel_path = image_data.get("original", "")
            #elif isinstance(image_data, str):
            #    original_rel_path = image_data
            
            #final_filename = ""

            #if original_rel_path:
            #    # Construct full source path
            #    src_path = os.path.join("scraped_data", original_rel_path)
            #    
            #    if os.path.exists(src_path):
            #        # Get extension (.jpg, .jpeg, .png)
            #        _, ext = os.path.splitext(src_path)
            #        if not ext: ext = ".jpg"
            #        
            #        # Generate new unique name: dr-ion-popescu.jpg
            #        new_filename = f"{slugify(doctor_name)}{ext}"
            #        
                    # Destination path
            #        dst_path = os.path.join(FLAT_IMAGES_DIR, new_filename)
                    
                    # Copy
            #        shutil.copy2(src_path, dst_path)
            #        final_filename = new_filename

            row["field_image"] = entry.get("field_image", "")

            # 3. Clean HTML Fields
            html_fields = [
                "field_about_doctor", "field_competencies", "field_professional_experience",
                "field_education_training", "field_conferences_seminars", 
                "field_awards_distinctions", "field_publications", "field_memberships"
            ]
            
            for field in html_fields:
                row[field] = clean_html_content(entry.get(field, ""))

            writer.writerow(row)

    print(f"Done! CSV created at: {OUTPUT_CSV}")
    print(f"Flattend images at: {FLAT_IMAGES_DIR}")

if __name__ == "__main__":
    main()