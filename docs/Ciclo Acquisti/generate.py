from googleapiclient.discovery import build

# --- CONFIGURAZIONE ---
API_KEY = '***GOOGLE_API_KEY_RIMOSSA***'       # Inserisci qui la tua API Key di Google
CHANNEL_ID = 'UCg3stNfSe5EAd-QCHsLv6tg'     # Inserisci l'ID del canale YouTube
# ----------------------

def get_playlists_ordered_by_videos(api_key, channel_id):
    youtube = build('youtube', 'v3', developerKey=api_key)
    playlists = []
    next_page_token = None

    print("Recupero delle playlist in corso...")

    # Loop per gestire la paginazione (se il canale ha molte playlist)
    while True:
        request = youtube.playlists().list(
            part="snippet,contentDetails",
            channelId=channel_id,
            maxResults=50,
            pageToken=next_page_token
        )
        response = request.execute()

        for item in response.get('items', []):
            title = item['snippet']['title']
            # Estrae il numero di video nella playlist
            video_count = item['contentDetails']['itemCount']
            playlist_id = item['id']
            url = f"https://www.youtube.com/playlist?list={playlist_id}"
            
            playlists.append({
                'title': title,
                'video_count': video_count,
                'url': url
            })

        next_page_token = response.get('nextPageToken')
        if not next_page_token:
            break

    # Ordina le playlist in base al numero di video (dalla più grande alla più piccola)
    playlists_sorted = sorted(playlists, key=lambda x: x['video_count'], reverse=True)
    
    return playlists_sorted

if __name__ == "__main__":
    try:
        classifica = get_playlists_ordered_by_videos(API_KEY, CHANNEL_ID)
        
        print("\n--- CLASSIFICA PLAYLIST PER NUMERO DI EPISODI ---")
        for i, pl in enumerate(classifica, 1):
            print(f"{i}. {pl['title']} -> {pl['video_count']} video")
            print(f"   Link: {pl['url']}\n")
            
    except Exception as e:
        print(f"Si è verificato un errore: {e}")