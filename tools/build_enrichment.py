"""
Builds data/enrichment.json: search aliases per product.

The catalog never mentions consumer names like "iPhone" or Hebrew
transliterations like "אייפון" / "סמסונג", so a literal search for them
finds nothing. This script adds them as aliases, from three curated maps:

  1. BRAND_HE     - Hebrew transliterations of brand names
  2. SUBCAT_EN    - English terms per subcategory (for English queries)
  3. FAMILY_RULES - product-family names (iPhone, Galaxy, PlayStation...)

The maps were drafted with Claude and reviewed by hand. The output is
committed, so production needs no LLM and indexing is deterministic.

Usage: python3 tools/build_enrichment.py data/catalog.csv data/enrichment.json
"""
import csv
import json
import re
import sys

BRAND_HE = {
    "Apple": ["אפל"], "Samsung": ["סמסונג"], "Sony": ["סוני"], "Xiaomi": ["שיאומי"],
    "Philips": ["פיליפס"], "Bosch": ["בוש"], "Dyson": ["דייסון"], "LG": ["אל ג'י"],
    "Google": ["גוגל"], "Microsoft": ["מיקרוסופט"], "Nintendo": ["נינטנדו"],
    "Braun": ["בראון"], "Tefal": ["טפאל"], "Ninja": ["נינג'ה"], "KitchenAid": ["קיצ'ן אייד"],
    "De'Longhi": ["דלונגי"], "Nespresso": ["נספרסו"], "Jura": ["יורה"], "Sage": ["סייג'"],
    "JBL": ["ג'יי בי אל"], "Bose": ["בוז"], "Sennheiser": ["זנהייזר"], "Marshall": ["מרשל"],
    "Lenovo": ["לנובו"], "Dell": ["דל"], "HP": ["אייץ' פי"], "ASUS": ["אסוס"], "Acer": ["אייסר"],
    "Logitech": ["לוג'יטק"], "Razer": ["רייזר"], "Canon": ["קנון"], "Epson": ["אפסון"],
    "DJI": ["די ג'יי איי"], "GoPro": ["גופרו"], "Garmin": ["גרמין"], "Huawei": ["וואווי"],
    "Electrolux": ["אלקטרולוקס"], "Roborock": ["רוברוק"], "iRobot": ["איירובוט", "רומבה"],
    "Dreame": ["דרים"], "Ecovacs": ["אקווקס"], "Karcher": ["קרשר"], "Makita": ["מקיטה"],
    "DeWalt": ["דיוולט"], "Weber": ["וובר"], "Rowenta": ["רוונטה"], "Kenwood": ["קנווד"],
    "Moulinex": ["מולינקס"], "Russell Hobbs": ["ראסל הובס"], "Electra": ["אלקטרה"],
    "Tadiran": ["תדיראן"], "Tornado": ["טורנדו"], "SodaStream": ["סודה סטרים"],
    "LEGO": ["לגו"], "Playmobil": ["פליימוביל"], "Oral-B": ["אורל בי"], "Remington": ["רמינגטון"],
    "Samsonite": ["סמסונייט"], "Nike": ["נייקי"], "Adidas": ["אדידס"], "Stanley": ["סטנלי"],
    "Lavazza": ["לוואצה"], "illy": ["אילי"], "Le Creuset": ["לה קרוזה"], "Amazon": ["אמזון"],
    "Beats": ["ביטס"], "Anker": ["אנקר"], "TCL": ["טי סי אל"], "Hisense": ["הייסנס"],
}

SUBCAT_EN = {
    "אמבטיה וניקיון": ["bathroom", "cleaning"], "מגבות וטקסטיל": ["towels", "textile"],
    "מצעים ושינה": ["bedding", "sheets", "pillow"], "סידור ואחסון": ["storage", "organizer"],
    "עיצוב ואקססוריז": ["decor", "home decor"], "ריהוט": ["furniture"], "תאורה": ["lighting", "lamp"],
    "גינון והשקיה": ["gardening", "irrigation"], "מנגלים ועל האש": ["grill", "bbq"],
    "ריהוט גן": ["garden furniture", "outdoor furniture"], "חתולים": ["cat"], "כלבים": ["dog"],
    "כללי לחיות מחמד": ["pet"], "בשמים ומארזי טיפוח": ["perfume", "fragrance"],
    "גילוח וטיפוח לגבר": ["shaver", "trimmer", "grooming"], "היגיינת פה": ["toothbrush", "oral care"],
    "הסרת שיער וטיפוח פנים": ["hair removal", "epilator", "skincare"],
    "מכשירי בריאות ומדידה": ["health", "blood pressure", "thermometer"],
    "עיסוי ורגיעה": ["massage", "massager"], "עיצוב שיער": ["hair dryer", "hair styling"],
    "שינה ורוגע": ["sleep", "relaxation"], "טלוויזיות": ["tv", "television"],
    "כלי נגינה": ["musical instrument"], "מקרנים ומסכי הקרנה": ["projector"],
    "משחקי קופסה ופאזלים": ["board game", "puzzle"], "סאונד לסלון": ["soundbar", "home theater"],
    "סטרימרים ואביזרי טלוויזיה": ["streamer", "streaming"], "קונסולות ואביזרי גיימינג": ["gaming", "console"],
    "תחביבים ויצירה": ["hobby", "crafts"], "כלי עבודה": ["tools", "power tools"], "רכב": ["car"],
    "איכות אוויר": ["air purifier", "humidifier"], "אקלים ומיזוג": ["fan", "heater", "air conditioner"],
    "בית חכם": ["smart home"], "גיהוץ וטיפול בבגדים": ["iron", "steamer"],
    "מוצרי חשמל גדולים": ["appliance", "fridge", "washing machine"], "ניקוי ופלאנלה": ["mop", "floor cleaner"],
    "שואבי אבק ורובוטים": ["vacuum", "vacuum cleaner", "robot vacuum"],
    "מדפסות וציוד משרדי": ["printer", "office"], "מחשבים ניידים וטאבלטים": ["laptop", "tablet", "notebook"],
    "מסכים ואביזרי תצוגה": ["monitor", "screen"], "מקלדות ועכברים": ["keyboard", "mouse"],
    "ריהוט ארגונומי": ["ergonomic", "desk", "office chair"], "רשת ואחסון": ["router", "storage", "ssd"],
    "שיחות וידאו ואודיו למשרד": ["webcam", "headset", "microphone"], "תיקים ומגנים": ["laptop bag", "case"],
    "אחסון ושימור מזון": ["food storage", "container"], "אפייה": ["baking"],
    "גאדג'טים ואביזרי מטבח": ["kitchen gadget"], "כלי הגשה ושולחן": ["tableware", "serving"],
    "מכשירי חשמל קטנים למטבח": ["kitchen appliance"], "סירים ומחבתות": ["pot", "pan", "cookware"],
    "סכינים וקרשי חיתוך": ["knife", "cutting board"], "אוזניות": ["headphones", "earbuds"],
    "מגנים ואביזרים לסלולר": ["phone case", "phone accessory"], "מטענים וסוללות": ["charger", "power bank"],
    "מצלמות ורחפנים": ["camera", "drone"], "סמארטפונים": ["smartphone", "phone", "mobile", "טלפון", "פלאפון", "סלולרי"],
    "קוראים אלקטרוניים וגאדג'טים": ["e-reader", "gadget"], "רמקולים": ["speaker"],
    "שעונים וצמידים חכמים": ["smartwatch", "fitness tracker"], "אביזרי נסיעה": ["travel accessory"],
    "אופניים ותחבורה חשמלית": ["bike", "bicycle", "scooter"], "בקבוקים ותרמוסים": ["bottle", "thermos"],
    "יוגה ופילאטיס": ["yoga", "pilates"], "ים ובריכה": ["beach", "pool"], "כושר ביתי": ["fitness", "gym"],
    "מזוודות ותיקי נסיעה": ["suitcase", "luggage"], "קמפינג וטיולים": ["camping", "hiking"],
    "אביזרי קפה": ["coffee accessory"], "גורמה ומארזים": ["gourmet", "gift box"], "יין ובר": ["wine", "bar"],
    "כוסות וספלים": ["mug", "cup"], "מכונות קפה": ["coffee machine", "espresso"],
    "פולי קפה וקפסולות": ["coffee beans", "capsules"], "תה ותה צמחים": ["tea"],
    "מדע ויצירה לילדים": ["kids science", "kids crafts"], "משחקי חוץ": ["outdoor toys"],
    "ציוד לתינוק": ["baby"], "צעצועים ומשחקים": ["toys"],
}

# (brand, regex on name or None, subcategory or None) -> aliases
FAMILY_RULES = [
    ("Apple", None, "סמארטפונים", ["iPhone", "אייפון", "איפון"]),
    ("Apple", r"macbook", None, ["מקבוק", "mac"]),
    ("Apple", r"ipad", None, ["אייפד", "איפד"]),
    ("Apple", None, "אוזניות", ["AirPods", "איירפודס", "אירפודס"]),
    ("Apple", None, "שעונים וצמידים חכמים", ["Apple Watch", "אפל ווטש"]),
    ("Apple", r"תג איתור", None, ["AirTag", "איירטאג"]),
    ("Samsung", None, "סמארטפונים", ["Galaxy", "גלקסי"]),
    ("Samsung", r"מתקפל", None, ["Galaxy Fold", "Z Fold", "פולד"]),
    ("Samsung", None, "שעונים וצמידים חכמים", ["Galaxy Watch", "גלקסי ווטש"]),
    ("Samsung", None, "מחשבים ניידים וטאבלטים", ["Galaxy Tab", "גלקסי טאב"]),
    ("Google", None, "סמארטפונים", ["Pixel", "פיקסל"]),
    ("Xiaomi", None, "סמארטפונים", ["Redmi", "רדמי"]),
    ("Sony", r"ps5", None, ["PlayStation", "PlayStation 5", "פלייסטיישן", "פלייסטיישן 5"]),
    ("Sony", r"dualsense", None, ["PS5 controller", "שלט פלייסטיישן", "ג'ויסטיק"]),
    ("Microsoft", r"xbox", None, ["אקסבוקס", "אקס בוקס"]),
    ("Nintendo", r"switch", None, ["סוויץ'", "נינטנדו סוויץ'"]),
    ("Microsoft", None, "מחשבים ניידים וטאבלטים", ["Surface", "סרפס"]),
    ("Dyson", None, "שואבי אבק ורובוטים", ["דייסון V15"]),
    ("iRobot", None, None, ["Roomba"]),
]


def norm(s: str) -> str:
    return s.strip().lower()


def build(catalog_path: str) -> dict:
    out = {}
    with open(catalog_path, encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            brand, sub, name = row["brand"].strip(), row["subcategory"], row["name"]
            aliases = []
            aliases += BRAND_HE.get(brand, [])
            aliases += SUBCAT_EN.get(sub, [])
            for r_brand, r_name, r_sub, r_aliases in FAMILY_RULES:
                if brand != r_brand:
                    continue
                if r_name and not re.search(r_name, name, re.IGNORECASE):
                    continue
                if r_sub and r_sub != sub:
                    continue
                aliases += r_aliases
            # de-duplicate, keep order, drop terms already in the name
            seen, uniq = set(), []
            for a in aliases:
                if norm(a) not in seen and norm(a) not in norm(name):
                    seen.add(norm(a))
                    uniq.append(a)
            if uniq:
                out[row["id"]] = {"aliases": uniq}
    return out


if __name__ == "__main__":
    src = sys.argv[1] if len(sys.argv) > 1 else "data/catalog.csv"
    dst = sys.argv[2] if len(sys.argv) > 2 else "data/enrichment.json"
    data = build(src)
    with open(dst, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=1, sort_keys=True)
    print(f"Wrote aliases for {len(data)} products to {dst}")
