import React, { useEffect, useState } from 'react';
import { useColorMode } from '@docusaurus/theme-common';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { Line } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
);

const COLORS = {
  fiber: { border: '#3b82f6', background: 'rgba(59, 130, 246, 0.1)' },
  swoole: { border: '#10b981', background: 'rgba(16, 185, 129, 0.1)' },
  cluster: { border: '#f59e0b', background: 'rgba(245, 158, 11, 0.1)' },
};

function makeDataset(label, data, color) {
  return {
    label,
    data,
    borderColor: color.border,
    backgroundColor: color.background,
    tension: 0.3,
    pointRadius: 4,
    pointHoverRadius: 6,
  };
}

function chartOptions(title, yLabel, isDark, commits) {
  const textColor = isDark ? '#e5e7eb' : '#374151';
  const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';

  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      title: { display: true, text: title, color: textColor, font: { size: 16 } },
      legend: { labels: { color: textColor } },
      tooltip: {
        callbacks: {
          title: (items) => {
            const idx = items[0]?.dataIndex;
            const commit = commits[idx];
            return commit ? `${items[0].label} (${commit})` : items[0].label;
          },
        },
      },
    },
    scales: {
      x: { ticks: { color: textColor }, grid: { color: gridColor } },
      y: {
        ticks: { color: textColor },
        grid: { color: gridColor },
        title: { display: true, text: yLabel, color: textColor },
      },
    },
  };
}

function extractSeries(history, runtime, key, field = 'opsPerSecond') {
  return history.map((entry) => entry.results?.[runtime]?.[key]?.[field] ?? null);
}

function NoData() {
  return (
    <div style={{ textAlign: 'center', padding: '2rem', opacity: 0.6 }}>
      <p>No benchmark history available yet.</p>
      <p>Benchmark data is collected automatically on each push to <code>main</code>.</p>
    </div>
  );
}

export default function BenchmarkCharts() {
  const [history, setHistory] = useState(null);
  const { colorMode } = useColorMode();
  const isDark = colorMode === 'dark';

  useEffect(() => {
    fetch('/benchmarks/history.json')
      .then((r) => r.json())
      .then(setHistory)
      .catch(() => setHistory([]));
  }, []);

  if (history === null) {
    return <p>Loading benchmark data...</p>;
  }

  if (history.length === 0) {
    return <NoData />;
  }

  const labels = history.map((e) => e.date);
  const commits = history.map((e) => e.commit);

  const throughputData = {
    labels,
    datasets: [
      makeDataset('Fiber: Message throughput', extractSeries(history, 'fiber', 'messageThroughput'), COLORS.fiber),
      makeDataset('Swoole: Message throughput', extractSeries(history, 'swoole', 'messageThroughput'), COLORS.swoole),
      makeDataset('Fiber: Fan-out', extractSeries(history, 'fiber', 'fanOut'), COLORS.fiber),
      makeDataset('Swoole: Fan-out', extractSeries(history, 'swoole', 'fanOut'), COLORS.swoole),
    ],
  };
  // Differentiate fan-out lines with dashes
  throughputData.datasets[2].borderDash = [5, 5];
  throughputData.datasets[3].borderDash = [5, 5];

  const latencyData = {
    labels,
    datasets: [
      makeDataset('Fiber: Ping-pong', extractSeries(history, 'fiber', 'pingPong'), COLORS.fiber),
      makeDataset('Swoole: Ping-pong', extractSeries(history, 'swoole', 'pingPong'), COLORS.swoole),
    ],
  };

  const memoryData = {
    labels,
    datasets: [
      makeDataset('Fiber: Peak memory', extractSeries(history, 'fiber', 'messageThroughput', 'peakMemoryBytes'), COLORS.fiber),
      makeDataset('Swoole: Peak memory', extractSeries(history, 'swoole', 'messageThroughput', 'peakMemoryBytes'), COLORS.swoole),
    ],
  };

  const clusterData = {
    labels,
    datasets: [
      makeDataset('Serialization throughput', extractSeries(history, 'cluster', 'serializationThroughput'), COLORS.cluster),
    ],
  };

  const chartStyle = { height: '350px', marginBottom: '2rem' };

  return (
    <div>
      <div style={chartStyle}>
        <Line data={throughputData} options={chartOptions('Message Throughput', 'ops/sec', isDark, commits)} />
      </div>
      <div style={chartStyle}>
        <Line data={latencyData} options={chartOptions('Ping-Pong Latency', 'ops/sec', isDark, commits)} />
      </div>
      <div style={chartStyle}>
        <Line data={memoryData} options={chartOptions('Memory Usage', 'bytes', isDark, commits)} />
      </div>
      <div style={chartStyle}>
        <Line data={clusterData} options={chartOptions('Cluster Performance', 'ops/sec', isDark, commits)} />
      </div>
    </div>
  );
}
