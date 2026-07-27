from faker import Faker

faker = Faker('it_IT')

def get_azienda_interna():
    return {
        "ragione_sociale": faker.company(),
        "partita_iva": faker.company_vat(),
        "indirizzo": faker.street_address(),
        "cap": faker.postcode(),
        "citta": faker.city(),
        "provincia": faker.state_abbr(),
        "telefono": faker.phone_number(),
        "email": faker.company_email(),
    }

def get_prodotto():
    return {
        "codice": faker.bothify(text='PRD-####-??', letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
        "nome": f"Prodotto {faker.word().capitalize()}",
        "prezzo": str(round(faker.pyfloat(left_digits=3, right_digits=2, positive=True), 2)),
    }

def get_fornitore():
    return {
        "company": faker.company(),
        "vat": faker.company_vat().replace('IT', ''),
        "email": faker.company_email(),
        "phone": faker.phone_number(),
        "website": faker.url(),
    }

def get_cliente(tipo="azienda"):
    cliente = {
        "type": tipo,
        "email": faker.ascii_free_email(),
        "phone": faker.phone_number(),
    }
    if tipo == "privato":
        cliente["first_name"] = faker.first_name()
        cliente["last_name"] = faker.last_name()
        cliente["tax_code"] = faker.ssn() # Approssimazione di un CF
    else:
        cliente["company"] = faker.company()
        cliente["vat"] = faker.company_vat().replace('IT', '')
        cliente["sdi_code"] = faker.bothify(text='???????', letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789')
    return cliente

def get_listino():
    return {
        "nome": f"Listino {faker.word().capitalize()} {faker.year()}",
        "descrizione": faker.sentence(),
    }
