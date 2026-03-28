UBold admin skin (scoped for WordPress)

Source CSS (update from your Coderthemes UBold build):
  assets/ubold/source/vendors.min.css
  assets/ubold/source/app.min.css

Regenerate the scoped bundle after replacing those files:
  npm install
  npm run build:ubold

Output (committed): assets/ubold/ubold.bridge.scoped.css
