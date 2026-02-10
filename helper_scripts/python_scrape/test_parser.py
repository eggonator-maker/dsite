#!/usr/bin/env python3
"""
Test the parser with actual HTML from web_fetch
"""

from advanced_scraper import DoctorProfileParser, save_to_json, save_to_drupal_csv
import json

# This is the actual HTML content structure from the doctor profile
# Based on the web_fetch result, the page seems to have a simple text structure
sample_doctor_html = """
<!DOCTYPE html>
<html>
<head>
    <title>DR. GUIȚĂ ALEXANDRU GIGEL - NORD</title>
</head>
<body>
    <h1>DR. GUIȚĂ ALEXANDRU GIGEL</h1>
    <div class="doctor-specialty">ATI</div>
    
    <section class="doctor-experience">
        <h2>Experiență profesională</h2>
        <ul>
            <li>2022 – Prezent: Medic Primar ATI, Nord Muntenia Hospital</li>
            <li>2020 – Director executiv, Direcția de Sănătate Publică Argeș</li>
            <li>2010 – 2018: Comandant/Director General, Spitalul Militar de Urgenţă „Dr.Ion Jianu"</li>
            <li>2001 – 2010: Șef Secția A.T.I., Spitalul Militar de Urgenţă „Dr.Ion Jianu"</li>
            <li>1993 – 2001: Ofițer-medic Anestezie Terapie intensivă, Spitalul Militar de Urgenţă „Dr.Ion Jianu"</li>
        </ul>
    </section>
    
    <section class="doctor-education">
        <h2>Educație și Formare</h2>
        <ul>
            <li>2011: Management Spitalicesc, Școala Națională de Sănătate Publică, Management și Perfecționare în Domeniul Sanitar București</li>
            <li>2005-2007: Master Managementul Serviciilor Sociale și de Sănătate, Universitatea din Pitesti</li>
            <li>1998: Medic Primar Anestezie și Terapie Intensivă, Ministerul Sănătății</li>
            <li>1990-1993 : Medic Specialist Anestezie și Terapie Intensiva, Rezidențiat în specialitatea Anestezie și Terapie intensivă, Universitatea de Medicină și Farmacie „Carol Davila" București</li>
            <li>1980 – 1986: Doctor-Medic Locotenent (1984); Ofiter-medic Specializarea Medicină Generală Institutul Medico-Militar Bucuresti Institutul de Medicină și Farmacie București Facultatea de Medicină Generală</li>
        </ul>
    </section>
</body>
</html>
"""

def test_parser():
    """Test the parser with sample HTML"""
    url = "https://nord.ro/medici/dr-guita-alexandru-gigel/"
    
    parser = DoctorProfileParser(sample_doctor_html, url)
    result = parser.parse()
    
    print("Parsed Doctor Profile:")
    print("=" * 60)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    print("=" * 60)
    
    # Save to JSON
    save_to_json([result], 'test_doctor.json')
    
    # Save to CSV for Drupal import
    save_to_drupal_csv([result], 'test_doctor.csv')
    
    return result

if __name__ == "__main__":
    test_parser()
