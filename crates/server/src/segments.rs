const BASIC: &str = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const EXTENDED: &str = "\u{000c}^{}\\[~]|€";

pub struct Analysis {
    pub encoding: &'static str,
    pub segments: usize,
    pub characters: usize,
    pub units: usize,
    pub ucs2_offsets: Vec<usize>,
}

pub fn analyze(text: &str) -> Analysis {
    let characters: Vec<char> = text.chars().collect();
    let ucs2_offsets: Vec<usize> = characters
        .iter()
        .enumerate()
        .filter_map(|(i, c)| (!BASIC.contains(*c) && !EXTENDED.contains(*c)).then_some(i))
        .collect();
    let unicode = !ucs2_offsets.is_empty();
    let costs: Vec<usize> = characters
        .iter()
        .map(|c| {
            if unicode {
                c.len_utf16()
            } else if EXTENDED.contains(*c) {
                2
            } else {
                1
            }
        })
        .collect();
    let units = costs.iter().sum();
    let (single, concatenated) = if unicode { (70, 67) } else { (160, 153) };
    let segments = if units == 0 {
        0
    } else if units <= single {
        1
    } else {
        let mut segments = 1;
        let mut used = 0;
        // ESC pairs and UTF-16 surrogate pairs cannot straddle a segment boundary.
        for cost in costs {
            if used + cost > concatenated {
                segments += 1;
                used = 0;
            }
            used += cost;
        }
        segments
    };
    Analysis {
        encoding: if unicode { "UCS-2" } else { "GSM-7" },
        segments,
        characters: characters.len(),
        units,
        ucs2_offsets,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn gsm_boundaries_and_extensions() {
        assert_eq!(analyze("").segments, 0);
        assert_eq!(analyze(&"a".repeat(160)).segments, 1);
        assert_eq!(analyze(&"a".repeat(161)).segments, 2);
        let extensions = analyze(&"^".repeat(153));
        assert_eq!(extensions.units, 306);
        assert_eq!(extensions.segments, 3);
    }

    #[test]
    fn unicode_counts_surrogates_without_splitting_them() {
        let result = analyze(&"😀".repeat(67));
        assert_eq!(result.encoding, "UCS-2");
        assert_eq!(result.characters, 67);
        assert_eq!(result.units, 134);
        assert_eq!(result.segments, 3);
        assert_eq!(analyze("Hi 👋").ucs2_offsets, vec![3]);
    }
}
