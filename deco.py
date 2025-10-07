# ==============================================================================
# 1. SETUP E INSTALLAZIONE DELLE DIPENDENZE
# ==============================================================================
print("1. Avvio servizio API (dipendenze gestite dal runtime).")
print("✅ Ambiente inizializzato.")

# ==============================================================================
# 2. IMPORTAZIONI E CONFIGURAZIONE CHIAVI
# ==============================================================================
import os
import sys
import json
import time
import shutil
import logging
import base64
import requests
import re
from datetime import datetime
from io import BytesIO
from pathlib import Path
try:
    from google.colab import userdata
except Exception:
    userdata = None
import cv2
from PIL import Image
import fitz # PyMuPDF
from bs4 import BeautifulSoup # NUOVO IMPORT per lo scraping
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Optional, List, Dict, Any
import uvicorn

# Configurazione logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

print("\n2. Configurazione delle chiavi API...")
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY') or (userdata.get('GEMINI_API_KEY') if userdata else None)
if GEMINI_API_KEY:
    os.environ['GEMINI_API_KEY'] = GEMINI_API_KEY
    print("✅ Chiave API Gemini Primaria configurata.")
else:
    logger.warning("ℹ️ GEMINI_API_KEY non impostata. Alcune API richiederanno questa variabile d'ambiente.")

# Chiave Secondaria (Opzionale)
GEMINI_API_KEY_2 = os.getenv('GEMINI_API_KEY_2') or (userdata.get('GEMINI_API_KEY_2') if userdata else None)
if GEMINI_API_KEY_2:
    os.environ['GEMINI_API_KEY_2'] = GEMINI_API_KEY_2
    print("✅ GEMINI_API_KEY_2 configurata e pronta per il bilanciamento del carico.")
else:
    logger.info("ℹ️ GEMINI_API_KEY_2 non trovata. Verrà usata solo la chiave primaria.")

# ==============================================================================
# 3. CLASSI DI SIMULAZIONE E VARIABILI DI FALLBACK
# ==============================================================================
print("\n3. Definizione delle classi di simulazione (Card Generator e DB Manager)...")

# Variabili di fallback (disabilitate in Colab)
MOONDREAM_AVAILABLE = False
QWEN_AVAILABLE = False
logger.warning("⚠️ I fallback Moondream e Qwen sono disabilitati in questo ambiente Colab.")

class ProductCardGenerator:
    """Simula la creazione di una card prodotto, salvando l'immagine originale ridimensionata."""
    def save_product_card(self, product_info, original_image_path, output_dir, image_name, region_id, supermercato_nome):
        # Pulizia del nome del prodotto per il filename
        product_name_clean = re.sub(r'[^\w\s-]', '', product_info.get('nome', 'prodotto')).strip()
        product_name_clean = re.sub(r'[-\s]+', '_', product_name_clean)[:30]
        filename = f"{image_name}_{product_name_clean}_card_{region_id}.jpg"
        filepath = Path(output_dir) / filename

        try:
            with Image.open(original_image_path) as img:
                if img.mode != 'RGB':
                    img = img.convert('RGB')
                img.thumbnail((600, 400), Image.Resampling.LANCZOS)
                img.save(filepath, 'JPEG', quality=85)
            return str(filepath)
        except Exception as e:
            logger.warning(f"⚠️ Errore simulazione card: {e}")
            return None

class DBManagerSimulator:
    """Simula l'interazione con un database."""
    def __init__(self):
        print("ℹ️ DBManagerSimulator inizializzato. I prodotti NON saranno salvati in un vero DB.")
        self.products = {}

    def save_products(self, job_id, products_list):
        if job_id not in self.products:
            self.products[job_id] = []

        for product in products_list:
            product['db_id'] = len(self.products[job_id]) + 1
            self.products[job_id].append(product)

        return products_list

    def update_job_status(self, job_id, status, progress, total_products, message):
        print(f"📊 Simulazione Aggiornamento Job {job_id}: Stato={status}, Progresso={progress}%, Prodotti={total_products}")
        return True

# ------------------------------------------------------------------------------
# NUOVA SEZIONE: SCRAPER PER VOLANTINI DECO
# ------------------------------------------------------------------------------
class DecoFlyerScraper:
    """Estrae URL e date di validità dei volantini da una pagina indice Deco."""

    BASE_URL = "https://supermercatideco.gruppoarena.it"
    TARGET_URL = f"{BASE_URL}/volantini/"

    def __init__(self):
        logger.info(f"🌐 Inizializzazione Scraper per {self.TARGET_URL}")

    def scrape_flyers(self):
        """Esegue lo scraping della pagina e restituisce una lista di dizionari."""
        flyers = []
        try:
            headers = {'User-Agent': 'Mozilla/5.0'}
            response = requests.get(self.TARGET_URL, headers=headers, timeout=15)
            response.raise_for_status()
            soup = BeautifulSoup(response.content, 'html.parser')

            # Ricerca dei contenitori principali dei volantini
            flyer_cards = soup.find_all('div', class_='flyer-card')

            if not flyer_cards:
                 logger.warning("⚠️ Classe 'flyer-card' non trovata. Tentativo con i link diretti ai PDF.")
                 # Fallback per i link diretti
                 pdf_links = soup.find_all('a', href=re.compile(r'/resources/.*\.pdf$', re.IGNORECASE))
                 if not pdf_links:
                     logger.warning("⚠️ Nessun link PDF trovato nel fallback.")
                     return []

                 # Costruzione delle card in modo rudimentale per il fallback
                 for link in pdf_links:
                     pdf_url = link['href']
                     if not pdf_url.startswith('http'):
                         pdf_url = self.BASE_URL + pdf_url
                     flyers.append({'name': pdf_url.split('/')[-1], 'url': pdf_url, 'validity': "Data non disponibile (Fallback)"})
                 return flyers

            for card in flyer_cards:
                # 1. Estrazione URL
                link_tag = card.find('a', href=True)
                if not link_tag: continue

                pdf_url = link_tag['href']
                if not pdf_url.startswith('http'):
                    pdf_url = self.BASE_URL + pdf_url

                # 2. Estrazione della Data (spesso in un tag 'p' o 'div' vicino al link)
                # Cerchiamo un testo che contenga date/validità
                promo_date = "Data non trovata"
                date_area = card.find('p', class_='flyer-validity') or card.find('p')
                if date_area:
                    promo_date = date_area.text.strip()

                # 3. Estrazione del Nome/Titolo
                name_tag = card.find('h4') or card.find('h3')
                name = name_tag.text.strip() if name_tag else "Volantino generico"

                flyers.append({
                    'name': name,
                    'url': pdf_url,
                    'validity': promo_date
                })

            logger.info(f"✅ Trovati {len(flyers)} volantini.")
            return flyers

        except requests.exceptions.RequestException as e:
            logger.error(f"❌ Errore durante lo scraping: {e}")
            return []
        except Exception as e:
            logger.error(f"❌ Errore generico di scraping: {e}")
            return []

# ==============================================================================
# 4. DEFINIZIONE DELLA CLASSE MULTIAIEXTRACTOR (CON CORREZIONI)
# ==============================================================================
print("\n4. Definizione della classe MultiAIExtractor...")

class MultiAIExtractor:
    def __init__(self, gemini_api_key="", gemini_api_key_2=None, job_id=None, db_manager=None, enable_fallback=False, supermercato_nome="SUPERMERCATO"):
        logger.info("🤖 Inizializzando estrattore Multi-AI con Gemini...")

        self.gemini_api_key = gemini_api_key or os.getenv('GEMINI_API_KEY')
        self.gemini_api_key_2 = gemini_api_key_2 or os.getenv('GEMINI_API_KEY_2')

        if not self.gemini_api_key:
             raise ValueError("GEMINI_API_KEY non è configurata.")

        # Ultimo modello stabile
        self.MODEL_NAME = "gemini-2.5-flash"
        self.gemini_url = f"https://generativelanguage.googleapis.com/v1beta/models/{self.MODEL_NAME}:generateContent?key={self.gemini_api_key}"
        self.gemini_url_2 = f"https://generativelanguage.googleapis.com/v1beta/models/{self.MODEL_NAME}:generateContent?key={self.gemini_api_key_2}" if self.gemini_api_key_2 else None

        self.job_id = job_id or str(int(time.time()))
        self.db_manager = db_manager
        self.enable_fallback = enable_fallback and (MOONDREAM_AVAILABLE or QWEN_AVAILABLE)
        self.current_key_index = 0
        self.api_keys = [self.gemini_api_key]
        self.api_urls = [self.gemini_url]

        if self.gemini_api_key_2:
            self.api_keys.append(self.gemini_api_key_2)
            self.api_urls.append(self.gemini_url_2)

        # Uso 'temp_processing' anziché '/content/temp_processing' per maggiore robustezza
        self.temp_dir = Path(f"temp_processing_{self.job_id}")
        self.temp_dir.mkdir(exist_ok=True)
        # Uso 'multi_ai_product_images' come directory locale
        self.product_images_dir = Path("multi_ai_product_images")
        self.product_images_dir.mkdir(exist_ok=True)

        self.card_generator = ProductCardGenerator()
        self.supermercato_nome = supermercato_nome

    def download_pdf_from_url(self, url):
        """Scarica PDF da URL"""
        try:
            logger.info(f"📥 Scaricando PDF da URL: {url}")
            headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
            response = requests.get(url, stream=True, timeout=30, headers=headers)
            if response.status_code == 200:
                filename = f"downloaded_pdf_{self.job_id}.pdf"
                pdf_path = self.temp_dir / filename
                with open(pdf_path, 'wb') as f:
                    for chunk in response.iter_content(chunk_size=8192):
                        f.write(chunk)
                logger.info(f"✅ PDF scaricato: {pdf_path}")
                return str(pdf_path)
            else:
                logger.error(f"❌ Errore HTTP download PDF: {response.status_code}")
                return None
        except Exception as e:
            logger.error(f"❌ Errore download PDF: {e}")
            return None

    def convert_pdf_to_images(self, pdf_path):
        """Converte PDF in immagini PNG"""
        try:
            logger.info(f"📄 Convertendo PDF in immagini: {pdf_path}")
            doc = fitz.open(pdf_path)
            image_paths = []
            for page_num in range(len(doc)):
                page = doc.load_page(page_num)
                mat = fitz.Matrix(2.0, 2.0)
                pix = page.get_pixmap(matrix=mat)
                image_filename = f"page_{page_num + 1}.png"
                image_path = self.temp_dir / image_filename
                pix.save(str(image_path))
                image_paths.append(str(image_path))
            doc.close()
            logger.info(f"✅ PDF convertito in {len(image_paths)} immagini")
            return image_paths
        except Exception as e:
            logger.error(f"❌ Errore conversione PDF: {e}")
            return []

    def image_to_base64(self, image_path):
        """Converte immagine in base64 per Gemini"""
        try:
            with Image.open(image_path) as img:
                if img.width > 1024 or img.height > 1024:
                    img.thumbnail((1024, 1024), Image.Resampling.LANCZOS)
                if img.mode != 'RGB':
                    img = img.convert('RGB')
                buffer = BytesIO()
                img.save(buffer, format='JPEG', quality=85)
                buffer.seek(0)
                return base64.b64encode(buffer.getvalue()).decode('utf-8')
        except Exception as e:
            logger.error(f"❌ Errore conversione base64: {e}")
            return None

    def get_next_api_config(self):
        """Ottiene la prossima configurazione API per bilanciare il carico"""
        if len(self.api_keys) > 1:
            self.current_key_index = (self.current_key_index + 1) % len(self.api_keys)

        current_key = self.api_keys[self.current_key_index]
        current_url = self.api_urls[self.current_key_index]

        return current_key, current_url

    def convert_price_to_float(self, price_str_or_float):
        """
        CORREZIONE: Converte una stringa o un float di prezzo in float.
        Rende la funzione robusta contro i valori float restituiti direttamente da Gemini.
        """

        if isinstance(price_str_or_float, (int, float)):
            cleaned_price = str(price_str_or_float)
        elif price_str_or_float is None:
            return 0.0
        else:
            cleaned_price = price_str_or_float.strip()

        if cleaned_price.lower() in ['non visibile', 'gratis', 'gratuito', '']:
            return 0.0

        cleaned_price = cleaned_price.replace('€', '').replace('$', '').replace(',', '.').strip()
        try:
            match = re.search(r'(\d+\.\d{2}|\d+\.\d{1}|\d+)', cleaned_price)
            if match:
                 return float(match.group(1))
            return 0.0
        except ValueError:
            return 0.0

    def save_product_to_db(self, product_info):
        """Salva le informazioni del prodotto nel DB (simulato)"""
        if self.db_manager:
            try:
                # La funzione convert_price_to_float è ora robusta
                product_info['prezzo_float'] = self.convert_price_to_float(product_info.get('prezzo'))

                saved_products = self.db_manager.save_products(self.job_id, [product_info])
                if saved_products:
                    return saved_products[0].get('db_id')
                return None
            except Exception as e:
                logger.error(f"❌ Errore salvataggio prodotto nel DB simulato: {e}")
                return None
        return None

    def _save_original_image_fallback(self, original_image_path, page_number):
        """Salva l'immagine originale come fallback se non si riescono a ritagliare i prodotti"""
        try:
            filename = f"job{self.job_id}_page{page_number}_original.jpg"
            filepath = self.product_images_dir / filename
            shutil.copy(original_image_path, filepath)
            return str(filepath)
        except Exception as e:
            logger.error(f"❌ Errore salvataggio immagine originale fallback: {e}")
            return None

    def save_product_image(self, original_image_path, bbox, product_info, page_number, product_index):
        """Salva l'immagine ridimensionata come card (simulata)"""
        try:
            card_filepath = self.card_generator.save_product_card(
                product_info,
                original_image_path,
                self.product_images_dir,
                f"job{self.job_id}_page{page_number}_prod{product_index}",
                self.job_id,
                self.supermercato_nome
            )
            return card_filepath
        except Exception as e:
            logger.error(f"❌ Errore salvataggio immagine prodotto/card: {e}")
            return None

    def analyze_with_gemini(self, image_path, retry_count=3):
        """Analizza immagine con Gemini AI con retry e restituisce la LISTA di prodotti"""
        for attempt in range(retry_count):
            try:
                current_key, current_url = self.get_next_api_config()

                image_base64 = self.image_to_base64(image_path)
                if not image_base64: return []

                prompt = """
Analizza questa immagine di un volantino di supermercato italiano e estrai SOLO le informazioni sui prodotti alimentari visibili.

Rispondi ESCLUSIVAMENTE con un JSON valido. Lo schema JSON richiesto è:
{
  "prodotti": [
    {
      "nome": "nome completo del prodotto",
      "marca": "marca del prodotto (es: Barilla, Mulino Bianco, Granarolo)",
      "categoria": "categoria (latticini, pasta, bevande, dolci, etc.)",
      "prezzo": "prezzo in euro se visibile (es: 2.49)",
      "descrizione": "breve descrizione del prodotto"
    }
  ]
}

Regole importanti:
- Il valore del campo "prezzo" DEVE essere una stringa (es: "2.49", "Non visibile").
- Estrai SOLO prodotti alimentari chiaramente visibili
- Se non vedi un prezzo, scrivi "Non visibile"
- Se non riconosci una marca, scrivi "Non identificata"
- Concentrati sui prodotti più evidenti e leggibili
- Massimo 10 prodotti per immagine
"""
                payload = {
                    "contents": [{
                        "parts": [
                            {"text": prompt},
                            {
                                "inline_data": {
                                    "mime_type": "image/jpeg",
                                    "data": image_base64
                                }
                            }
                        ]
                    }],
                    "generationConfig": {
                        "temperature": 0.1,
                        "topK": 1,
                        "topP": 1,
                        # Aumentato il limite per evitare troncamento (Correzione Errore JSON parse)
                        "maxOutputTokens": 4096,
                        "responseMimeType": "application/json"
                    }
                }
                headers = {'Content-Type': 'application/json'}
                response = requests.post(current_url, json=payload, headers=headers, timeout=45)

                if response.status_code == 200:
                    result = response.json()
                    if 'candidates' in result and len(result['candidates']) > 0:
                        text_response = result['candidates'][0]['content']['parts'][0]['text']

                        # Pulizia del codice markdown (se presente)
                        cleaned_response = text_response.strip()
                        if cleaned_response.startswith("```json"):
                            cleaned_response = cleaned_response[7:]
                        if cleaned_response.endswith("```"):
                            cleaned_response = cleaned_response[:-3]
                        cleaned_response = cleaned_response.strip()

                        try:
                            product_data = json.loads(cleaned_response)
                            if 'prodotti' in product_data and isinstance(product_data['prodotti'], list):
                                logger.info(f"✅ Gemini ha estratto {len(product_data['prodotti'])} prodotti.")
                                return product_data['prodotti']
                            else:
                                logger.warning(f"⚠️ Risposta Gemini non contiene lista 'prodotti' valida.")
                                return []
                        except json.JSONDecodeError as json_e:
                            logger.error(f"❌ Errore JSON parse (probabilmente troncato): {json_e}")
                            if attempt == retry_count - 1: return []
                            continue
                    else:
                        logger.warning(f"⚠️ Risposta Gemini vuota o senza candidati.")
                        return []
                # Gestione errori API
                elif response.status_code in [429, 500, 503]:
                    wait_time = min(5 * (attempt + 1), 30)
                    logger.warning(f"⏳ Rate limit o errore server ({response.status_code}). Attesa {wait_time}s.")
                    if attempt < retry_count - 1:
                        time.sleep(wait_time)
                        continue
                    else:
                        logger.error(f"❌ Fallimento dopo {retry_count} tentativi.")
                        return []
                else:
                    logger.error(f"❌ Errore non gestito Gemini (Status: {response.status_code}): {response.text}")
                    return []
            except requests.exceptions.RequestException as req_e:
                wait_time = 5 * (attempt + 1)
                logger.error(f"❌ Errore nella richiesta: {req_e}. Attesa {wait_time}s.")
                if attempt < retry_count - 1:
                    time.sleep(wait_time)
                    continue
                else:
                    logger.error(f"❌ Errore richiesta non recuperabile dopo {retry_count} tentativi.")
                    return []
            except Exception as e:
                logger.error(f"❌ Errore inatteso durante l'analisi: {e}")
                return []

        return []

    def process_pdf(self, pdf_source, source_type="url"):
        """Processa un PDF (scaricato o locale), estraendo prodotti per pagina."""

        if source_type == "url":
            pdf_path = self.download_pdf_from_url(pdf_source)
        else:
            pdf_path = pdf_source

        if not pdf_path:
            logger.error("❌ Nessuna fonte PDF valida fornita.")
            if self.db_manager: self.db_manager.update_job_status(self.job_id, "failed", 0, 0, "Nessuna fonte PDF valida.")
            return []

        logger.info(f"🚀 Inizio elaborazione PDF: {pdf_path}")
        image_paths = self.convert_pdf_to_images(pdf_path)

        if not image_paths:
            logger.error("❌ Nessuna immagine creata dal PDF.")
            if self.db_manager: self.db_manager.update_job_status(self.job_id, "failed", 0, 0, "Nessuna immagine creata dal PDF.")
            return []

        total_products_extracted = 0
        all_extracted_products = []

        if self.db_manager: self.db_manager.update_job_status(self.job_id, "processing", 0, len(image_paths), "Inizio analisi immagini...")

        for i, image_path in enumerate(image_paths):
            print("\n" + "="*50)
            logger.info(f"📊 Progresso: Pagina {i+1}/{len(image_paths)} - {Path(image_path).name}")
            print("="*50)

            progress_percent = (i + 1) * 100 // len(image_paths)
            if self.db_manager: self.db_manager.update_job_status(self.job_id, "processing", progress_percent, len(image_paths), f"Analizzando pagina {i + 1}...")

            extracted_products = self.analyze_with_gemini(image_path)

            if extracted_products:
                logger.info(f"🎉 Estratti {len(extracted_products)} prodotti da {Path(image_path).name}")

                for prod_index, product in enumerate(extracted_products):
                    product['pagina'] = i + 1
                    # Aggiunge dettagli del job per tracciare i prodotti
                    product['job_id'] = self.job_id
                    product['supermercato'] = self.supermercato_nome

                    simulated_bbox = [0, 0, 100, 100]
                    card_path = self.save_product_image(image_path, simulated_bbox, product, i + 1, prod_index + 1)
                    product['immagine_prodotto_card'] = card_path or 'Non disponibile'

                    db_id = self.save_product_to_db(product)
                    if db_id: product['db_id'] = db_id

                    all_extracted_products.append(product)
                    total_products_extracted += 1
            else:
                logger.warning(f"😞 Nessun prodotto estratto da {Path(image_path).name}.")
                self._save_original_image_fallback(image_path, i + 1)

            if i < len(image_paths) - 1:
                logger.info("⏳ Pausa di 5 secondi tra le immagini per il rate limiting...")
                time.sleep(5)

        logger.info(f"✅ Elaborazione PDF completata. Totale prodotti estratti: {total_products_extracted}")

        if self.db_manager: self.db_manager.update_job_status(self.job_id, "completed", 100, total_products_extracted, "Elaborazione completata.")

        return all_extracted_products

    def cleanup_temp_files(self):
        """Pulisce i file temporanei"""
        try:
            logger.info(f"🧹 Pulizia file temporanei in: {self.temp_dir}")
            shutil.rmtree(self.temp_dir, ignore_errors=True)
            logger.info("✅ Pulizia completata.")
        except Exception as e:
            logger.error(f"❌ Errore durante la pulizia dei file temporanei: {e}")

    def run(self, pdf_source, source_type="url"):
        """Metodo principale per avviare l'estrazione"""
        logger.info(f"🚀 Avvio estrazione per job {self.job_id}")

        try:
            results = self.process_pdf(pdf_source, source_type)

            output_file = f'gemini_results_{self.job_id}.json'
            results_data = {
                'timestamp': datetime.now().isoformat(),
                'method': 'Gemini AI Extractor (Colab)',
                'total_products': len(results),
                'products': results
            }

            with open(output_file, 'w', encoding='utf-8') as f:
                json.dump(results_data, f, indent=2, ensure_ascii=False)

            logger.info(f"\n==============================================")
            logger.info(f"💾 Risultati salvati in {output_file}")
            logger.info(f"📊 Totale prodotti estratti: {len(results)}")
            logger.info(f"==============================================")
            return results

        except Exception as e:
            logger.error(f"❌ Errore fatale nel metodo RUN: {e}")
            if self.db_manager:
                 self.db_manager.update_job_status(self.job_id, "failed", 0, 0, f"Errore fatale: {e}")
            return []
        finally:
            self.cleanup_temp_files()

GeminiOnlyExtractor = MultiAIExtractor

# ==============================================================================
# 5. ESECUZIONE DEL TEST (ELABORAZIONE MULTI-VOLANTINO)
# ==============================================================================
# ==============================================================================
# 5. SERVIZIO API FASTAPI (per deploy su Render)
# ==============================================================================
app = FastAPI(title="Deco Volantino Extractor API", version="1.0.0")

class ExtractRequest(BaseModel):
    url: str
    supermercato_nome: Optional[str] = "Supermercati Deco Arena"

@app.get("/health")
def health():
    return {"status": "ok"}

@app.get("/flyers")
def get_flyers():
    scraper = DecoFlyerScraper()
    flyers = scraper.scrape_flyers()
    return {"count": len(flyers), "flyers": flyers}

@app.post("/extract")
def extract(req: ExtractRequest):
    if not os.getenv('GEMINI_API_KEY'):
        raise HTTPException(status_code=500, detail="GEMINI_API_KEY non impostata nel server.")
    db_sim = DBManagerSimulator()
    extractor = MultiAIExtractor(
        gemini_api_key=os.getenv('GEMINI_API_KEY'),
        gemini_api_key_2=os.getenv('GEMINI_API_KEY_2'),
        job_id=str(int(time.time())),
        db_manager=db_sim,
        supermercato_nome=req.supermercato_nome
    )
    results = extractor.run(pdf_source=req.url, source_type="url")
    # Arricchisce i prodotti con l'URL del volantino
    for product in results:
        product['volantino_url'] = req.url
    return {"job_id": extractor.job_id, "total_products": len(results), "products": results}

@app.get("/extract_all")
def extract_all(limit: Optional[int] = None, supermercato_nome: Optional[str] = "Supermercati Deco Arena"):
    if not os.getenv('GEMINI_API_KEY'):
        raise HTTPException(status_code=500, detail="GEMINI_API_KEY non impostata nel server.")
    scraper = DecoFlyerScraper()
    flyers = scraper.scrape_flyers()
    if limit is not None:
        flyers = flyers[:limit]

    all_results = []
    for i, flyer in enumerate(flyers):
        db_sim = DBManagerSimulator()
        extractor = MultiAIExtractor(
            gemini_api_key=os.getenv('GEMINI_API_KEY'),
            gemini_api_key_2=os.getenv('GEMINI_API_KEY_2'),
            job_id=f"service_{int(time.time())}_{i+1}",
            db_manager=db_sim,
            supermercato_nome=supermercato_nome
        )
        extracted_products = extractor.run(pdf_source=flyer['url'], source_type="url")
        for product in extracted_products:
            product['volantino_name'] = flyer.get('name')
            product['volantino_validita'] = flyer.get('validity')
            product['volantino_url'] = flyer.get('url')
        all_results.extend(extracted_products)

    return {"count": len(all_results), "products": all_results}

if __name__ == "__main__":
    port = int(os.getenv("PORT", "8000"))
    uvicorn.run("deco:app", host="0.0.0.0", port=port)