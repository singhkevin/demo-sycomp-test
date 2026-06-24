const https = require('https');

https.get('https://restcountries.com/v3.1/all?fields=cca2,name,currencies', (res) => {
    let data = '';
    res.on('data', chunk => data += chunk);
    res.on('end', () => {
        const countries = JSON.parse(data);
        const sycompCountryData = {};
        const copyCommands = [];

        countries.forEach(c => {
            const code = c.cca2.toLowerCase();
            const name = c.name.common;
            const key = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            
            let currency = '';
            let symbol = '';
            let decimals = 2; // Default to 2

            if (c.currencies) {
                const currencyKeys = Object.keys(c.currencies);
                if (currencyKeys.length > 0) {
                    currency = currencyKeys[0];
                    symbol = c.currencies[currency].symbol || currency;
                }
            }
            
            // JPY, KRW, TWD typically have 0 decimals
            if (['JPY', 'KRW', 'TWD', 'VND'].includes(currency)) {
                decimals = 0;
            }

            // Only add if we have basic data
            if (code && name && currency && key) {
                sycompCountryData[code] = {
                    name,
                    currency,
                    symbol,
                    decimals,
                    code: code.toUpperCase(),
                    key
                };
                
                // Command to copy the flag
                copyCommands.push(`[ -f "${code}.svg" ] && cp "${code}.svg" "${key}.svg"`);
            }
        });

        const fs = require('fs');
        fs.writeFileSync('country_data.json', JSON.stringify(sycompCountryData, null, 4));
        fs.writeFileSync('copy_flags.sh', copyCommands.join('\n'));
        console.log(`Generated data for ${Object.keys(sycompCountryData).length} countries.`);
    });
}).on('error', err => {
    console.error('Error:', err.message);
});
