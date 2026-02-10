import os
import json
import requests
import time
import re
import unicodedata
from bs4 import BeautifulSoup
from urllib.parse import urljoin

# Configuration
BASE_URL = "https://nord.ro"
START_URL = "https://nord.ro/medici/?specialitate=&nume=&locatie=Pite%C8%99ti"
OUTPUT_DIR = "scraped_data"
IMAGES_DIR = os.path.join(OUTPUT_DIR, "images")
JSON_FILE = os.path.join(OUTPUT_DIR, "doctors.json")

# Create directories
os.makedirs(IMAGES_DIR, exist_ok=True)

# Headers to mimic a real browser
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
}

# Mapping Accordion Titles (Romanian) to Drupal Field Machine Names
ACCORDION_MAPPING = {
    "despre": "field_about_doctor",
    "despre medic": "field_about_doctor",
    "competențe": "field_competencies",
    "competente": "field_competencies",
    "experiență": "field_professional_experience",
    "experiență profesională": "field_professional_experience",
    "experienta profesionala": "field_professional_experience",
    "educație": "field_education_training",
    "educație și formare": "field_education_training",
    "educatie si formare": "field_education_training",
    "certificări": "field_education_training",
    "certificari": "field_education_training",
    "conferințe": "field_conferences_seminars",
    "conferințe și seminarii": "field_conferences_seminars",
    "conferinte si seminarii": "field_conferences_seminars",
    "conferinţe": "field_conferences_seminars", # Alternative char
    "congrese": "field_conferences_seminars",
    "congrese și seminarii": "field_conferences_seminars",
    "congrese si seminarii": "field_conferences_seminars",
    "cursuri": "field_conferences_seminars",
    "prezență": "field_conferences_seminars",
    "prezenta": "field_conferences_seminars",
    "distincții": "field_awards_distinctions",
    "premii": "field_awards_distinctions",
    "premii și distincții": "field_awards_distinctions",
    "publicații": "field_publications",
    "publicatii": "field_publications",
    "membru": "field_memberships",
    "membru în societăți": "field_memberships",
}

def clean_text(text):
    """Cleans extra whitespace from text."""
    if text:
        return text.strip()
    return ""

def slugify(value):
    """
    Normalizes string, converts to lowercase, removes non-alpha characters,
    and converts spaces to hyphens. Native implementation.
    """
    value = str(value)
    value = unicodedata.normalize('NFKD', value).encode('ascii', 'ignore').decode('ascii')
    value = re.sub(r'[^\w\s-]', '', value).lower()
    return re.sub(r'[-\s]+', '-', value).strip('-')

def download_images(image_entries, doctor_name):
    """
    Downloads a list of images for a specific doctor.
    image_entries: list of tuples (url, descriptor) e.g. [('...jpg', 'original'), ('...-300x300.jpg', '300w')]
    Returns: Dictionary of {descriptor: local_relative_path}
    """
    if not image_entries:
        return {}

    doctor_slug = slugify(doctor_name)
    doctor_img_dir = os.path.join(IMAGES_DIR, doctor_slug)
    os.makedirs(doctor_img_dir, exist_ok=True)

    saved_images = {}

    for img_url, descriptor in image_entries:
        if not img_url or img_url.startswith('data:'):
            continue

        try:
            # Clean URL query params
            img_url_clean = img_url.split('?')[0]
            ext = os.path.splitext(img_url_clean)[1]
            if not ext or len(ext) > 5:
                ext = ".jpg"
            
            # Create filename: {descriptor}.jpg (e.g., 300w.jpg, original.jpg)
            filename = f"{descriptor}{ext}"
            filepath = os.path.join(doctor_img_dir, filename)
            
            # Store relative path for JSON
            relative_path = os.path.join("images", doctor_slug, filename)

            # Check if file already exists to skip redownload
            if os.path.exists(filepath):
                saved_images[descriptor] = relative_path
                continue

            # Download
            response = requests.get(img_url, headers=HEADERS, stream=True)
            if response.status_code == 200:
                with open(filepath, 'wb') as f:
                    for chunk in response.iter_content(1024):
                        f.write(chunk)
                saved_images[descriptor] = relative_path
        except Exception as e:
            print(f"Error downloading {descriptor} image for {doctor_name}: {e}")
    
    return saved_images

def parse_profile(url):
    """Extracts data from a single doctor's profile page."""
    print(f"Scraping profile: {url}")
    try:
        response = requests.get(url, headers=HEADERS)
        soup = BeautifulSoup(response.content, 'html.parser')

        doctor_data = {
            "url": url,
            "title": "", 
            "field_primary_speciality": "",
            "field_image": {}, # Changed to dict to hold multiple sizes
            "field_about_doctor": "",
            "field_competencies": "",
            "field_professional_experience": "",
            "field_education_training": "",
            "field_conferences_seminars": "",
            "field_awards_distinctions": "",
            "field_publications": "",
            "field_memberships": ""
        }

        # 1. Extract Name (Title)
        name_tag = soup.find('h1')
        if name_tag:
            doctor_data["title"] = clean_text(name_tag.text)
        
        # 2. Extract Speciality
        subheader = soup.find(class_='subheader')
        if subheader:
            h2_in_subheader = subheader.find('h2')
            if h2_in_subheader:
                doctor_data["field_primary_speciality"] = clean_text(h2_in_subheader.text)
            else:
                doctor_data["field_primary_speciality"] = clean_text(subheader.text)

        # 3. Extract Images (Src and Srcset)
        image_entries = [] # List of (url, descriptor)
        
        # Find the main image tag
        img_tag = soup.select_one('.detalii-medic-stanga img')
        
        # Fallback if not found in left column
        if not img_tag:
             portfolio_div = soup.find(id='full_width_portfolio')
             if portfolio_div and portfolio_div.get('data-featured-img'):
                 # If found in data attr, we only have one size usually
                 image_entries.append((urljoin(url, portfolio_div.get('data-featured-img')), 'original'))

        if img_tag:
            # 3a. Get Original (src or data-nectar-img-src)
            src = img_tag.get('data-nectar-img-src') or img_tag.get('src')
            if src and not src.startswith('data:'):
                image_entries.append((urljoin(url, src), 'original'))
            
            # 3b. Get Sizes from srcset
            srcset = img_tag.get('srcset')
            if srcset:
                # Format: "url 804w, url 300w, ..."
                # Split by comma
                parts = srcset.split(',')
                for part in parts:
                    part = part.strip()
                    if not part: continue
                    
                    # Split by space. Last element is descriptor (300w), first is URL
                    subparts = part.split(' ')
                    if len(subparts) >= 2:
                        img_u = subparts[0]
                        descriptor = subparts[-1] # e.g., '300w'
                        
                        # Add to list
                        if not img_u.startswith('data:'):
                            image_entries.append((urljoin(url, img_u), descriptor))

        # Download all collected images
        doctor_data["field_image"] = download_images(image_entries, doctor_data["title"])

        # 4. Extract Accordions
        toggles = soup.find_all(class_='toggle')
        
        for toggle in toggles:
            title_node = toggle.find(class_='toggle-title')
            if not title_node:
                continue
            
            header_text = clean_text(title_node.text).lower()
            
            content_div = toggle.find(class_='inner-toggle-wrap')
            if not content_div:
                divs = toggle.find_all('div', recursive=False)
                if divs:
                    content_div = divs[0]

            if content_div:
                html_content = content_div.decode_contents().strip()
                
                mapped = False
                for key_snippet, field_name in ACCORDION_MAPPING.items():
                    if key_snippet in header_text:
                        doctor_data[field_name] = html_content
                        mapped = True
                        break
                
                if not mapped:
                    print(f"  Unmapped accordion found: '{header_text}'")

        return doctor_data

    except Exception as e:
        print(f"Failed to parse profile {url}: {e}")
        return None

def main():
    print(f"Starting scraper on: {START_URL}")
    all_doctors = []

    response = requests.get(START_URL, headers=HEADERS)
    soup = BeautifulSoup(response.content, 'html.parser')

    links = set()
    profile_containers = soup.select('.portofoliu-medici')
    
    for container in profile_containers:
        a_tag = container.find('a', href=True)
        if a_tag:
            href = a_tag['href']
            if '/medici/' in href:
                full_url = urljoin(BASE_URL, href)
                links.add(full_url)
    
    print(f"Found {len(links)} doctor profiles.")

    for link in links:
        data = parse_profile(link)
        if data:
            all_doctors.append(data)
        time.sleep(1)

    with open(JSON_FILE, 'w', encoding='utf-8') as f:
        json.dump(all_doctors, f, ensure_ascii=False, indent=4)
    
    print(f"Done! Scraped {len(all_doctors)} doctors.")
    print(f"Data saved to {JSON_FILE}")
    print(f"Images saved to {IMAGES_DIR}")

if __name__ == "__main__":
    main()