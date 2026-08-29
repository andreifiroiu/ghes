const WIDTH = 300;
const HEIGHT = 80;
const GAP_RATIO = 0.2;

/**
 * Format a 'YYYY-MM-DD' day as a short, locale-independent label.
 *
 * @param {string} day
 * @returns {string}
 */
function shortDay(day) {
    const [, month, date] = day.split('-');

    return `${date}/${month}`;
}

/**
 * A dependency-free daily bar chart.
 *
 * Bars are drawn in a fixed viewBox and stretched to the container, so the
 * chart is responsive without measuring anything in JS. Days on which the
 * scraper failed are drawn in the accent colour, so an outage is visible even
 * when the value itself is zero — that combination is exactly the interesting
 * case, and a plain zero-height bar would render as nothing at all.
 *
 * @param {Object} props
 * @param {Array<{day: string, value: number, failed?: number}>} props.data
 * @param {string} props.label Accessible name for the series.
 * @param {string} [props.color] Bar fill for normal days.
 */
export default function DailyBarChart({ data = [], label, color = '#0A1128' }) {
    if (data.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">No data.</p>;
    }

    // Guard the divisor: an all-zero window would otherwise divide by zero and
    // render every bar as NaN.
    const max = Math.max(1, ...data.map((d) => d.value));
    const slot = WIDTH / data.length;
    const barWidth = Math.max(slot * (1 - GAP_RATIO), 0.5);
    const total = data.reduce((sum, d) => sum + d.value, 0);

    return (
        <div>
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                preserveAspectRatio="none"
                className="h-24 w-full sm:h-32"
                role="img"
                aria-label={`${label}: ${total} total across ${data.length} days`}
            >
                {data.map((d, i) => {
                    const height = (d.value / max) * HEIGHT;
                    const isFailed = (d.failed ?? 0) > 0;

                    return (
                        <g key={d.day}>
                            {/*
                              A failed day with zero events still needs a mark,
                              so give it a minimum visible height.
                            */}
                            <rect
                                x={i * slot + (slot - barWidth) / 2}
                                y={HEIGHT - Math.max(height, isFailed ? 3 : 0)}
                                width={barWidth}
                                height={Math.max(height, isFailed ? 3 : 0)}
                                fill={isFailed ? '#FF5733' : color}
                                opacity={d.value === 0 && !isFailed ? 0.15 : 1}
                            >
                                <title>
                                    {`${d.day}: ${d.value}${isFailed ? ` · ${d.failed} failed run(s)` : ''}`}
                                </title>
                            </rect>
                            {/* Baseline tick so empty days remain legible. */}
                            {d.value === 0 && !isFailed && (
                                <rect
                                    x={i * slot + (slot - barWidth) / 2}
                                    y={HEIGHT - 1}
                                    width={barWidth}
                                    height={1}
                                    fill="#D1D5DB"
                                />
                            )}
                        </g>
                    );
                })}
            </svg>

            <div className="mt-1 flex justify-between text-xs text-gray-500">
                <span>{shortDay(data[0].day)}</span>
                <span className="font-medium text-gray-700">
                    {total.toLocaleString()} total · peak {max.toLocaleString()}
                </span>
                <span>{shortDay(data[data.length - 1].day)}</span>
            </div>
        </div>
    );
}
