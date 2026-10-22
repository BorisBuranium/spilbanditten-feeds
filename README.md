# Spilbanditten Feeds

WordPress-plugin til spilbanditten.dk. Henter partnernes produktfeeds hver nat, gemmer prishistorik, viser prissammenligning med kortkoden `[sb_priser]` og henter produktbilleder fra feedene.

Feed-URL'er og andre indstillinger ligger kun i WordPress-databasen, aldrig i dette repo.

## Udgivelser
Et tag som `v1.6.1` (skal passe med `Version:` i `spilbanditten-feeds.php`) starter GitHub Action'en `release`, som laver en udgivelse med `spilbanditten-feeds.zip` og `spilbanditten-feeds.json`. Plugin'et på sitet tjekker seneste udgivelse hver 6. time og opdaterer sig selv. Push uden tag gør intet.
